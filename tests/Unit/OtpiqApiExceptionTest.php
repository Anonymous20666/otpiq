<?php

namespace Rstacode\Otpiq\Tests\Unit;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Rstacode\Otpiq\Exceptions\OtpiqApiException;

class OtpiqApiExceptionTest extends TestCase
{
    public function test_constructor_sets_properties(): void
    {
        $errors = ['phone' => ['Phone number is required']];
        $responseData = ['message' => 'Validation failed', 'errors' => $errors];

        $exception = new OtpiqApiException(
            'Test error',
            400,
            null,
            $errors,
            $responseData
        );

        $this->assertEquals('Test error', $exception->getMessage());
        $this->assertEquals(400, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertEquals($errors, $exception->getErrors());
        $this->assertEquals($responseData, $exception->getResponseData());
    }

    public function test_constructor_with_previous_exception(): void
    {
        $previous = new \RuntimeException('Previous error');
        $exception = new OtpiqApiException('Error', 500, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_from_guzzle_exception_with_response(): void
    {
        $responseBody = json_encode([
            'message' => 'Unauthorized',
            'errors' => ['token' => ['Invalid token']],
        ]);

        $response = new Response(401, [], $responseBody);
        $request = new Request('POST', 'https://api.otpiq.com/api/sms');
        $guzzleException = new ClientException('Client error', $request, $response);

        $exception = OtpiqApiException::fromGuzzleException($guzzleException);

        $this->assertStringContainsString('Unauthorized', $exception->getMessage());
        $this->assertEquals(401, $exception->getCode());
        $this->assertSame($guzzleException, $exception->getPrevious());
        $this->assertEquals(['token' => ['Invalid token']], $exception->getErrors());
    }

    public function test_from_guzzle_exception_with_error_key_in_response(): void
    {
        $responseBody = json_encode([
            'error' => 'Something went wrong',
        ]);

        $response = new Response(500, [], $responseBody);
        $request = new Request('GET', 'https://api.otpiq.com/api/info');
        $guzzleException = new ClientException('Server error', $request, $response);

        $exception = OtpiqApiException::fromGuzzleException($guzzleException);

        $this->assertStringContainsString('Something went wrong', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
    }

    public function test_from_guzzle_exception_without_response(): void
    {
        $request = new Request('POST', 'https://api.otpiq.com/api/sms');
        $guzzleException = new ConnectException('Connection timed out', $request);

        $exception = OtpiqApiException::fromGuzzleException($guzzleException);

        $this->assertStringContainsString('Connection timed out', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertEmpty($exception->getErrors());
        $this->assertNull($exception->getResponseData());
    }

    public function test_from_guzzle_exception_with_invalid_json_body(): void
    {
        $response = new Response(500, [], 'not json');
        $request = new Request('GET', 'https://api.otpiq.com/api/info');
        $guzzleException = new ClientException('Server error', $request, $response);

        $exception = OtpiqApiException::fromGuzzleException($guzzleException);

        $this->assertStringContainsString('Server error', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
    }

    public function test_get_errors_returns_empty_array_by_default(): void
    {
        $exception = new OtpiqApiException('Error');

        $this->assertEquals([], $exception->getErrors());
    }

    public function test_has_errors_returns_true_when_errors_exist(): void
    {
        $exception = new OtpiqApiException('Error', 400, null, ['field' => ['Required']]);

        $this->assertTrue($exception->hasErrors());
    }

    public function test_has_errors_returns_false_when_no_errors(): void
    {
        $exception = new OtpiqApiException('Error');

        $this->assertFalse($exception->hasErrors());
    }

    public function test_get_response_data_returns_null_by_default(): void
    {
        $exception = new OtpiqApiException('Error');

        $this->assertNull($exception->getResponseData());
    }

    public function test_is_credit_error_with_credit_message(): void
    {
        $exception = new OtpiqApiException('OTPIQ API Error: Insufficient credit');

        $this->assertTrue($exception->isCreditError());
    }

    public function test_is_credit_error_with_insufficient_message(): void
    {
        $exception = new OtpiqApiException('OTPIQ API Error: You have insufficient balance');

        $this->assertTrue($exception->isCreditError());
    }

    public function test_is_credit_error_with_your_credit_response_data(): void
    {
        $exception = new OtpiqApiException(
            'Error',
            402,
            null,
            [],
            ['yourCredit' => 0, 'requiredCredit' => 200]
        );

        $this->assertTrue($exception->isCreditError());
    }

    public function test_is_credit_error_returns_false_for_non_credit_error(): void
    {
        $exception = new OtpiqApiException('OTPIQ API Error: Unauthorized');

        $this->assertFalse($exception->isCreditError());
    }

    public function test_is_auth_error_returns_true_for_401(): void
    {
        $exception = new OtpiqApiException('Unauthorized', 401);

        $this->assertTrue($exception->isAuthError());
    }

    public function test_is_auth_error_returns_false_for_non_401(): void
    {
        $exception = new OtpiqApiException('Not Found', 404);

        $this->assertFalse($exception->isAuthError());
    }

    public function test_is_validation_error_returns_true_for_400_with_errors(): void
    {
        $exception = new OtpiqApiException('Validation failed', 400, null, ['phone' => ['Required']]);

        $this->assertTrue($exception->isValidationError());
    }

    public function test_is_validation_error_returns_false_for_400_without_errors(): void
    {
        $exception = new OtpiqApiException('Bad request', 400);

        $this->assertFalse($exception->isValidationError());
    }

    public function test_is_validation_error_returns_false_for_non_400(): void
    {
        $exception = new OtpiqApiException('Error', 500, null, ['field' => ['Error']]);

        $this->assertFalse($exception->isValidationError());
    }

    public function test_is_rate_limit_error_returns_true_for_429(): void
    {
        $exception = new OtpiqApiException('Too many requests', 429);

        $this->assertTrue($exception->isRateLimitError());
    }

    public function test_is_rate_limit_error_returns_false_for_non_429(): void
    {
        $exception = new OtpiqApiException('Error', 500);

        $this->assertFalse($exception->isRateLimitError());
    }

    public function test_is_trial_mode_error_returns_true(): void
    {
        $exception = new OtpiqApiException('OTPIQ API Error: Account is in trial mode');

        $this->assertTrue($exception->isTrialModeError());
    }

    public function test_is_trial_mode_error_returns_false(): void
    {
        $exception = new OtpiqApiException('OTPIQ API Error: Unauthorized');

        $this->assertFalse($exception->isTrialModeError());
    }

    public function test_is_spending_threshold_error_returns_true(): void
    {
        $exception = new OtpiqApiException(
            'Error',
            403,
            null,
            [],
            ['spendingThreshold' => 1000]
        );

        $this->assertTrue($exception->isSpendingThresholdError());
    }

    public function test_is_spending_threshold_error_returns_false(): void
    {
        $exception = new OtpiqApiException('Error', 403, null, [], ['message' => 'Forbidden']);

        $this->assertFalse($exception->isSpendingThresholdError());
    }

    public function test_is_sender_id_error_returns_true(): void
    {
        $exception = new OtpiqApiException('OTPIQ API Error: Invalid senderId');

        $this->assertTrue($exception->isSenderIdError());
    }

    public function test_is_sender_id_error_returns_false(): void
    {
        $exception = new OtpiqApiException('OTPIQ API Error: Unauthorized');

        $this->assertFalse($exception->isSenderIdError());
    }

    public function test_get_first_error_returns_null_when_no_errors(): void
    {
        $exception = new OtpiqApiException('Error');

        $this->assertNull($exception->getFirstError());
    }

    public function test_get_first_error_returns_string_error(): void
    {
        $exception = new OtpiqApiException('Error', 400, null, ['phone' => 'Phone is required']);

        $this->assertEquals('Phone is required', $exception->getFirstError());
    }

    public function test_get_first_error_returns_first_item_of_array_error(): void
    {
        $exception = new OtpiqApiException('Error', 400, null, [
            'phone' => ['Phone is required', 'Phone must be valid'],
        ]);

        $this->assertEquals('Phone is required', $exception->getFirstError());
    }

    public function test_get_remaining_credit_from_your_credit(): void
    {
        $exception = new OtpiqApiException('Error', 402, null, [], ['yourCredit' => 500]);

        $this->assertEquals(500, $exception->getRemainingCredit());
    }

    public function test_get_remaining_credit_from_remaining_credit(): void
    {
        $exception = new OtpiqApiException('Error', 402, null, [], ['remainingCredit' => 300]);

        $this->assertEquals(300, $exception->getRemainingCredit());
    }

    public function test_get_remaining_credit_prefers_your_credit(): void
    {
        $exception = new OtpiqApiException('Error', 402, null, [], [
            'yourCredit' => 500,
            'remainingCredit' => 300,
        ]);

        $this->assertEquals(500, $exception->getRemainingCredit());
    }

    public function test_get_remaining_credit_returns_null_when_not_available(): void
    {
        $exception = new OtpiqApiException('Error', 500, null, [], ['message' => 'Error']);

        $this->assertNull($exception->getRemainingCredit());
    }

    public function test_get_required_credit_returns_value(): void
    {
        $exception = new OtpiqApiException('Error', 402, null, [], ['requiredCredit' => 200]);

        $this->assertEquals(200, $exception->getRequiredCredit());
    }

    public function test_get_required_credit_returns_null_when_not_available(): void
    {
        $exception = new OtpiqApiException('Error', 500);

        $this->assertNull($exception->getRequiredCredit());
    }

    public function test_get_rate_limit_wait_minutes_returns_value(): void
    {
        $exception = new OtpiqApiException('Error', 429, null, [], ['waitMinutes' => 5]);

        $this->assertEquals(5, $exception->getRateLimitWaitMinutes());
    }

    public function test_get_rate_limit_wait_minutes_returns_null_when_not_available(): void
    {
        $exception = new OtpiqApiException('Error', 429);

        $this->assertNull($exception->getRateLimitWaitMinutes());
    }
}
