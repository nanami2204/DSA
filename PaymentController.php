<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Custom\CustomHelpers;
use Illuminate\Support\Facades\DB;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Exception;
use App\Models\Customer;
use App\Models\TwoTimeSlot;
use Carbon\Carbon;

class PaymentController extends Controller
{
    private const REQUIRED_SESSION_KEYS = ['payment_pending', 'order_id', 'paymentAmount', 'uid', 'pid'];

    public function index()
    {
        if (!session()->has('payment_pending') || session('payment_pending') === false) {
            CustomHelpers::customLog('paymentlog','Redirecting to dashboard due to no pending payment');
            return $this->redirectToDashboard('No pending payment');
        }

        if (!$this->validateSessionData(self::REQUIRED_SESSION_KEYS)) {
            Log::warning('Attempted to access payment page without required session data');
            return $this->redirectToDashboard('Invalid payment session');
        }

        $sessionData = $this->getSessionData(self::REQUIRED_SESSION_KEYS);
        $msg = 'Accessing payment page | Session Data: ' . json_encode($sessionData, JSON_PRETTY_PRINT);
        CustomHelpers::customLog('paymentlog', $msg);

        return response()->view('razor-pay')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Sat, 26 Jul 1997 05:00:00 GMT');
    }

    public function pay(Request $request)
    {
        try {
            CustomHelpers::customLog('paymentlog', 'Initiating payment | Request: ' . json_encode($request->all()));

            $sessionData = $this->getRequiredSessionData();
            $key = CustomHelpers::razorPayKey();
            $api = new Api($key['keyId'], $key['keySecret']);

            $paymentDetails = $this->preparePaymentDetails($sessionData['paymentAmount'], $sessionData['pid']);
            $razorpayOrder = $api->order->create($paymentDetails);

            CustomHelpers::customLog('paymentlog', 'Razorpay order created | Order ID: ' . $razorpayOrder['id']);

            Session::put('razorpay_order_id', $razorpayOrder['id']);

            return response()->json([
                'id' => $razorpayOrder['id'],
                'amount' => $paymentDetails['amount'],
                'currency' => $paymentDetails['currency'],
                'key' => $key['keyId'],
            ]);
        } catch (Exception $e) {
            Log::error('Error during payment initiation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            CustomHelpers::customLog('paymentlog', 'Error during payment initiation: ' . $e->getMessage());

            return response()->json(['error' => 'Payment could not be processed. Please try again.'], 500);
        }
    }

    public function success(Request $request)
    {
        CustomHelpers::customLog('paymentlog','Payment callback received', $request->all());
        CustomHelpers::customLog('paymentlog', 'Payment callback received | Request: ' . json_encode($request->all()));

        try {
            $this->verifyPaymentSignature($request);
            CustomHelpers::customLog('paymentlog', 'Payment signature verified');

            $paymentStatus = $this->getPaymentStatus($request->input('razorpay_payment_id'));

            if ($paymentStatus === 'captured') {
                $this->handleSuccessfulPayment($request->input('razorpay_payment_id'));
                $this->updateSessionData($request);

                return response()->json([
                    'success' => true,
                    'redirect' => route('sales.payment.success.page'),
                ]);
            } else {
                return $this->handleFailedPayment($paymentStatus);
            }
        } catch (SignatureVerificationError $e) {
            Log::error('Razorpay signature verification failed', ['error' => $e->getMessage()]);
            CustomHelpers::customLog('paymentlog', 'Signature verification failed: ' . $e->getMessage());
            return $this->handleFailedPayment('signature_verification_failed');
        } catch (Exception $e) {
            Log::error('Unexpected error in payment callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            CustomHelpers::customLog('paymentlog', 'Unexpected error during payment: ' . $e->getMessage());
            return $this->handleFailedPayment('unexpected_error');
        }
    }

    public function successPage(Request $request)
    {
        $paymentId = $request->session()->get('razorpay_payment_id');
        $amount = $request->session()->get('payment_amount');

        if (!$paymentId || !$amount || Session::get('payment_pending') !== false) {
            Log::error('Payment information not found or payment not completed', [
                'razorpay_payment_id' => $paymentId,
                'amount' => $amount,
                'payment_pending' => Session::get('payment_pending')
            ]);
            CustomHelpers::customLog('paymentlog', 'Payment information missing or incomplete on success page');

            return $this->redirectToDashboard('Payment information not found or payment not completed');
        }

        $payment = (object) [
            'razorpay_payment_id' => $paymentId,
            'amount' => $amount
        ];

        CustomHelpers::customLog('paymentlog',"Payment information passed to success page:", [
            'payment_id' => $payment->razorpay_payment_id,
            'amount' => $payment->amount
        ]);
        CustomHelpers::customLog('paymentlog', 'Displaying success page with payment info | Payment ID: ' . $payment->razorpay_payment_id);

        // Clear payment session data after the process is fully completed
        $this->clearPaymentSessionData();

        return response()->view('success', compact('payment'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Sat, 26 Jul 1997 05:00:00 GMT');
    }

    public function failed()
    {
        CustomHelpers::customLog('paymentlog','Payment failed page accessed');
        CustomHelpers::customLog('paymentlog', 'Accessed payment failed page');
        return response()->view('payment.failed')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Sat, 26 Jul 1997 05:00:00 GMT');
    }

    private function validateSessionData(array $requiredKeys): bool
    {
        $isValid = count(array_intersect_key(array_flip($requiredKeys), Session::all())) === count($requiredKeys);
        return $isValid;
    }

    private function getSessionData(array $keys): array
    {
        $sessionData = array_intersect_key(Session::all(), array_flip($keys));
        CustomHelpers::customLog('paymentlog', 'Retrieved session data: ' . json_encode($sessionData));
        return $sessionData;
    }

    private function getRequiredSessionData(): array
    {
        $sessionData = $this->getSessionData(['paymentAmount', 'pid', 'uid']);

        if (count($sessionData) !== 3) {
            CustomHelpers::customLog('paymentlog', 'Required session data missing: ' . json_encode($sessionData));
            throw new Exception('Required payment information is missing');
        }

        CustomHelpers::customLog('paymentlog', 'Required session data: ' . json_encode($sessionData));
        return $sessionData;
    }

    private function preparePaymentDetails(float $amount, string $pid): array
    {
        $paymentDetails = [
            'amount' => (string) round($amount * 100),
            'currency' => 'INR',
            'receipt' => (string) $pid,
        ];

        CustomHelpers::customLog('paymentlog', 'Prepared payment details: ' . json_encode($paymentDetails));
        return $paymentDetails;
    }

    private function verifyPaymentSignature(Request $request): void
    {
        $key = CustomHelpers::razorPayKey();
        $api = new Api($key['keyId'], $key['keySecret']);

        $attributes = [
            'razorpay_order_id' => $request->input('razorpay_order_id'),
            'razorpay_payment_id' => $request->input('razorpay_payment_id'),
            'razorpay_signature' => $request->input('razorpay_signature')
        ];

        $api->utility->verifyPaymentSignature($attributes);
        CustomHelpers::customLog('paymentlog', 'Payment signature verified successfully: ' . json_encode($attributes));

        Session::put('razorpay_signature', $attributes['razorpay_signature']);
    }

    private function updateSessionData(Request $request): void
    {
        Session::put('razorpay_payment_id', $request->input('razorpay_payment_id'));
        Session::put('payment_amount', Session::get('paymentAmount'));
        Session::put('payment_pending', false);
    }

    private function clearPaymentSessionData(): void
    {
        Session::forget([
            'razorpay_payment_id',
            'payment_amount',
            'order_id',
            'paymentAmount',
            'uid',
            'pid',
            'razorpay_order_id',
            'razorpay_signature',
            'payment_pending',
            'notification_type',
        ]);
    }

    private function handleSuccessfulPayment($razorpayPaymentId): void
    {

        $this->updateCustomerPaymentDetails($razorpayPaymentId);
        if (Session::has('cust')) {
            CustomHelpers::customLog('paymentlog', 'Updating Customer info in Techno SPA Integration Table.');
            $this->updateTechnoSpaIntegration();
        } else {
            CustomHelpers::customLog('paymentlog', 'Handling customer acquisition.');
            $this->updateCustomerAcquisition();
        }

        if (Session::get('notification_type') === 'customer_renewal') {
            $this->sendCustomerRenewalSms();
        }
        if (Session::get('notification_type') === 'customer_acquisition') {
            // TODO: Send SMS
        }
        if (Session::has('renewalSlotData') && Session::get('renewalSlotData.isRenewalSlotCreated') === 'true') {
            $this->updateTwoTimeSlot();
        }
    }

    private function updateTwoTimeSlot(): void
    {
        $renewalSlotData = Session::get('renewalSlotData');
        CustomHelpers::customLog('paymentlog',"renewalSlotData: ", [$renewalSlotData]);

        if (!$renewalSlotData) {
            Log::error('updateTwoTimeSlot: No renewal slot data found.');
            return;
        }

        if (!is_array($renewalSlotData) || !isset($renewalSlotData['isRenewalSlotCreated'], $renewalSlotData['createdRenewalTimeSlot'], $renewalSlotData['customerId'])) {
            Log::error('updateTwoTimeSlot: Invalid renewalSlotData structure');
            return;
        }

        $response = $this->updateTwoTimeSlotTable($renewalSlotData, ['customerId' => $renewalSlotData['customerId']]);

        if ($response['success']) {
            CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: Time slots updated successfully.', $response['data']);
        } else {
            Log::error('updateTwoTimeSlot: Error updating time slots.', $response['errors']);
        }

        Session::forget('renewalSlotData');
    }

    private function updateTwoTimeSlotTable($arr, $custPay)
    {
        $response = [
            'success' => false,
            'message' => '',
            'data' => [],
            'errors' => []
        ];

        if ($arr['isRenewalSlotCreated'] === 'true' && $arr['createdRenewalTimeSlot']) {
            CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: Checking if renewal slot needs to be created.', [
                'isRenewalSlotCreated' => $arr['isRenewalSlotCreated'],
                'createdRenewalTimeSlot' => $arr['createdRenewalTimeSlot']
            ]);

            $slots = json_decode($arr['createdRenewalTimeSlot'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $response['message'] = 'Invalid JSON in createdRenewalTimeSlot';
                $response['errors'][] = json_last_error_msg();
                Log::error('updateTwoTimeSlot: ' . $response['message']);
                return $response;
            }
            CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: Time slots received.', ['slots' => $slots]);

            $pkgStartSeconds = $this->timeToSecondsRenew($this->formatTime($arr['pkgStTime']));
            $pkgEndSeconds = $this->timeToSecondsRenew($this->formatTime($arr['pkgEndTime']));
            if ($pkgStartSeconds === false || $pkgEndSeconds === false) {
                $response['message'] = 'Invalid package start or end time format';
                $response['errors'][] = 'Time conversion failed';
                Log::error('updateTwoTimeSlot: ' . $response['message']);
                return $response;
            }
            CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: Package times converted to seconds.', [
                'pkgStartSeconds' => $pkgStartSeconds,
                'pkgEndSeconds' => $pkgEndSeconds
            ]);

            $packageDurationSeconds = $this->calculateDurationRenew($pkgStartSeconds, $pkgEndSeconds);
            CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: Package duration calculated.', [
                'packageDurationSeconds' => $packageDurationSeconds
            ]);

            $totalSlotSeconds = 0;
            $validSlots = [];
            $slotsToCreate = [];

            foreach ($slots as $slotData) {
                $startSeconds = $this->timeToSecondsRenew($this->formatTime($slotData['startTime']));
                $endSeconds = $this->timeToSecondsRenew($this->formatTime($slotData['endTime']));
                if ($startSeconds === false || $endSeconds === false) {
                    Log::warning('updateTwoTimeSlot: Invalid time format for slot.', [
                        'slot_name' => $slotData['slotName'],
                        'start_time' => $slotData['startTime'],
                        'end_time' => $slotData['endTime']
                    ]);
                    continue;
                }
                $slotDuration = $this->calculateDurationRenew($startSeconds, $endSeconds);

                CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: Processing slot.', [
                    'slot_name' => $slotData['slotName'],
                    'startSeconds' => $startSeconds,
                    'endSeconds' => $endSeconds,
                    'slotDuration' => $slotDuration,
                    'isExisting' => $slotData['isExisting']
                ]);

                $totalSlotSeconds += $slotDuration;

                if ($slotData['isExisting'] === true) {
                    $existingSlot = TwoTimeSlot::where('customer_id', $custPay['customerId'])
                        ->where('id', $slotData['id'])
                        ->first();

                    if ($existingSlot) {
                        $existingStartSeconds = $this->timeToSecondsRenew($this->formatTime($existingSlot->start_time));
                        $existingEndSeconds = $this->timeToSecondsRenew($this->formatTime($existingSlot->end_time));
                        if ($existingStartSeconds === false || $existingEndSeconds === false) {
                            Log::warning('updateTwoTimeSlot: Invalid time format for existing slot.', [
                                'slot_name' => $slotData['slotName'],
                                'start_time' => $existingSlot->start_time,
                                'end_time' => $existingSlot->end_time
                            ]);
                            continue;
                        }
                        $existingSlotDuration = $this->calculateDurationRenew($existingStartSeconds, $existingEndSeconds);

                        if (
                            $existingSlotDuration <= $packageDurationSeconds &&
                            $this->formatTime($existingSlot->start_time) === $this->formatTime($slotData['startTime']) &&
                            $this->formatTime($existingSlot->end_time) === $this->formatTime($slotData['endTime'])
                        ) {
                            $validSlots[] = $existingSlot;
                            CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: Existing slot validated.', [
                                'slot_name' => $slotData['slotName'],
                                'start_time' => $existingSlot->start_time,
                                'end_time' => $existingSlot->end_time,
                                'duration' => $existingSlotDuration
                            ]);
                        } else {
                            Log::warning('updateTwoTimeSlot: Existing slot does not match current data or exceeds package duration. Will be updated.', [
                                'slot_name' => $slotData['slotName'],
                                'current_start' => $slotData['startTime'],
                                'current_end' => $slotData['endTime'],
                                'existing_start' => $existingSlot->start_time,
                                'existing_end' => $existingSlot->end_time,
                                'existing_duration' => $existingSlotDuration,
                                'package_duration' => $packageDurationSeconds
                            ]);
                            $slotsToCreate[] = [
                                'id' => $existingSlot->id,
                                'customer_id' => $custPay['customerId'],
                                'slot_name' => $slotData['slotName'],
                                'start_time' => $this->formatTime($slotData['startTime']),
                                'end_time' => $this->formatTime($slotData['endTime']),
                            ];
                        }
                    } else {
                        Log::warning('updateTwoTimeSlot: Existing slot not found in database. Will be created.', [
                            'slot_name' => $slotData['slotName']
                        ]);
                        $slotsToCreate[] = [
                            'customer_id' => $custPay['customerId'],
                            'slot_name' => $slotData['slotName'],
                            'start_time' => $this->formatTime($slotData['startTime']),
                            'end_time' => $this->formatTime($slotData['endTime']),
                        ];
                    }
                } else {
                    $slotsToCreate[] = [
                        'customer_id' => $custPay['customerId'],
                        'slot_name' => $slotData['slotName'],
                        'start_time' => $this->formatTime($slotData['startTime']),
                        'end_time' => $this->formatTime($slotData['endTime']),
                    ];
                }
            }

            CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: Time slots processed.', [
                'validExistingSlots' => count($validSlots),
                'slotsToCreate' => count($slotsToCreate)
            ]);

            if ($totalSlotSeconds <= $packageDurationSeconds) {
                try {
                    DB::beginTransaction();
                    foreach ($slotsToCreate as $slot) {
                        if (isset($slot['id'])) {
                            TwoTimeSlot::where('id', $slot['id'])->update([
                                'customer_id' => $slot['customer_id'],
                                'slot_name' => $slot['slot_name'],
                                'start_time' => $slot['start_time'],
                                'end_time' => $slot['end_time']
                            ]);
                        } else {
                            TwoTimeSlot::create($slot);
                        }
                    }
                    DB::commit();

                    $response['success'] = true;
                    $response['message'] = 'Time slots processed successfully.';
                    $response['data'] = [
                        'validExistingSlots' => count($validSlots),
                        'processedSlots' => count($slotsToCreate)
                    ];
                    CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: ' . $response['message'], $response['data']);
                } catch (\Exception $e) {
                    DB::rollBack();
                    $response['message'] = 'Error processing time slots: ' . $e->getMessage();
                    $response['errors'][] = $e->getMessage();
                    Log::error('updateTwoTimeSlot: ' . $response['message'], ['exception' => $e]);
                }
            } else {
                $response['message'] = 'Total slot time exceeds package duration.';
                $response['errors'][] = $response['message'];
                Log::error('updateTwoTimeSlot: ' . $response['message'], [
                    'totalSlotSeconds' => $totalSlotSeconds,
                    'packageDurationSeconds' => $packageDurationSeconds
                ]);
            }
        } else {
            $response['message'] = 'No renewal slot update required.';
            CustomHelpers::customLog('paymentlog','updateTwoTimeSlot: ' . $response['message']);
        }

        return $response;
    }

    private function timeToSecondsRenew($time)
    {
        if (!is_string($time) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            Log::warning('timeToSecondsRenew: Invalid time format', ['time' => $time]);
            return false;
        }

        list($hours, $minutes, $seconds) = explode(':', $time);
        return ((int)$hours * 3600) + ((int)$minutes * 60) + (int)$seconds;
    }

    private function calculateDurationRenew($startSeconds, $endSeconds)
    {
        if ($endSeconds < $startSeconds) {
            return (86400 - $startSeconds) + $endSeconds;
        } else {
            return $endSeconds - $startSeconds;
        }
    }

    private function formatTime($time)
    {
        try {
            return Carbon::parse($time)->format('H:i:s');
        } catch (\Exception $e) {
            Log::warning('formatTime: Unable to parse time', ['time' => $time, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function handleFailedPayment($reason)
    {
        Log::error('Payment failed', ['reason' => $reason]);
        CustomHelpers::customLog('paymentlog', 'Payment failed: ' . $reason);

        $this->clearPaymentSessionData();

        return response()->json([
            'success' => false,
            'redirect' => route('sales.payment.failed'),
        ]);
    }

    private function getPaymentStatus($paymentId)
    {
        $key = CustomHelpers::razorPayKey();
        $api = new Api($key['keyId'], $key['keySecret']);
        $payment = $api->payment->fetch($paymentId);
        return $payment->status;
    }

    private function redirectToDashboard($reason)
    {
        $this->clearPaymentSessionData();
        return redirect()->route('sales.dashboard');
    }

    private function updateCustomerPaymentDetails($razorpayPaymentId): void
    {
        DB::table('customer_payment_details')->where('cp_id', Session::get('pid'))
            ->update([
                "payment_status" => 1,
                "razorpay_payment_id" => $razorpayPaymentId,
                "razorpay_order_id" => Session::get('razorpay_order_id'),
                "payment_date" => now(),
                "updated_at" => now(),
                "razorpay_signature" => Session::get('razorpay_signature')
            ]);

        CustomHelpers::customLog('paymentlog', 'Customer payment details updated | Razorpay Payment ID: ' . $razorpayPaymentId);
    }

    private function updateTechnoSpaIntegration(): void
    {
        $arr = Session::get('cust');
        $technoData = Session::get("technoData");

        DB::table('techno_spa_integration')->insert($technoData);

        $url = "sales/updateCustomer";
        $custData = $arr;
        unset($custData['paymentType']);
        $custData['customerStatus'] = "Active";
        $custData['paymentStatus'] = 1;
        $make_call = CustomHelpers::postApi($url, $custData);
        $response = json_decode($make_call, true);

        CustomHelpers::customLog('paymentlog', 'Customer renewal handled | Response: ' . json_encode($response));
    }

    private function updateCustomerAcquisition(): void
    {
        DB::table('customers_acquisition')->where('cust_acq_id', Session::get('uid'))
            ->update([
                "payment_status" => 1,
                "order_id" => Session::get('order_id')
            ]);

        CustomHelpers::customLog('paymentlog', 'Customer acquisition updated | UID: ' . Session::get('uid'));
    }

    private function sendCustomerRenewalSms(): void
    {
        CustomHelpers::customLog('paymentlog',"sending sms to customer for there renewal, Notification Type: ", [Session::get('notification_type')]);
        try {
            $arr = Session::get('cust');
            $technoData = Session::get('technoData');

            $mobile_no = $arr['mobile_no'] ?? Customer::where('customer_id', $arr['id'])->value('mobile_no');

            if (!$mobile_no) {
                Log::error('Mobile number not found for customer', ['customer_id' => $arr['id']]);
                return;
            }

            if (strpos($mobile_no, '91') !== 0) {
                $mobile_no = '91' . $mobile_no;
            }

            $fullName = $arr['customerName'];
            $firstName = explode(' ', trim($fullName))[0];
            $watts = explode('.', $technoData['Pkg_Watts'])[0];

            $package = isset($arr['isDiscountApplied']) && $arr['isDiscountApplied'] === 'true'
                ? explode('.', $arr['originalAmount'])[0]
                : explode('.', $arr['subscriptionPackageAmount'])[0];

            $amount = (int)round($arr['subscriptionPackageAmount']);
            $params = [
                'number' => $mobile_no,
                'name' => $firstName,
                'caf' => $arr['cafNo'],
                'meter' => $technoData['Meter_No'],
                'channel' => $technoData['Channel_No'],
                'package' => $package,
                'amount' => $amount,
                'wat' => $watts,
            ];

            CustomHelpers::customLog('paymentlog','SMS params: ', [$params]);
            $url = 'http://sales.omcpower.co.in/sms2.php?' . http_build_query($params);
            $sendSmsRes = file_get_contents($url);
            CustomHelpers::customLog('paymentlog',"API response: " . $sendSmsRes);
            $response = json_decode($sendSmsRes, true);
            if ($response['status'] === 'success') {
                CustomHelpers::customLog('paymentlog',"SMS notification sent to customer " . $firstName . " with mobile number " . $mobile_no);
                CustomHelpers::customLog('paymentlog', "SMS notification sent successfully.");

                Session::forget(['cust', 'technoData', 'notification_type']);
            } else {
                Log::warning("SMS notification failed for customer {$firstName}. Response: " . $sendSmsRes);
                CustomHelpers::customLog('paymentlog',"SMS notification failed for customer {$firstName}. Response: " . $sendSmsRes);
                CustomHelpers::customLog('paymentlog', "SMS notification failed: " . json_encode($sendSmsRes));
            }
        } catch (Exception $e) {
            Log::error("Error sending SMS to customer: " . $e->getMessage());
            CustomHelpers::customLog('paymentlog', "Error sending SMS to customer: " . $e->getMessage());
        }
    }
}
