<?php

namespace Rstacode\Otpiq\Exceptions;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

class OtpiqApiException extends Exception
{
    protected array $errors = [];
    protected ?array $responseData = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $errors = [],
        ?array $responseData = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
        $this->responseData = $responseData;
    }

    public static function fromGuzzleException(GuzzleException $e): self
    {
        $message = $e->getMessage();
        $code = 0;
        $errors = [];
        $responseData = null;

        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true) ?? [];
            $responseData = $body;
            $message = $body['message'] ?? $body['error'] ?? $message;
            $code = $response->getStatusCode();
            $errors = $body['errors'] ?? [];
        }

        return new self("OTPIQ API Error: {$message}", $code, $e, $errors, $responseData);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getResponseData(): ?array
    {
        return $this->responseData;
    }

    public function isCreditError(): bool
    {
        return $this->messageContains('credit') ||
            $this->messageContains('insufficient') ||
            $this->hasResponseKey('yourCredit');
    }

    public function isAuthError(): bool
    {
        return $this->getCode() === 401;
    }

    public function isValidationError(): bool
    {
        return $this->getCode() === 400 && $this->hasErrors();
    }

    public function isRateLimitError(): bool
    {
        return $this->getCode() === 429;
    }

    public function isTrialModeError(): bool
    {
        return $this->messageContains('trial mode');
    }

    public function isSpendingThresholdError(): bool
    {
        return $this->hasResponseKey('spendingThreshold');
    }

    public function isSenderIdError(): bool
    {
        return $this->messageContains('senderid');
    }

    public function getFirstError(): ?string
    {
        if (!$this->hasErrors()) {
            return null;
        }

        $firstError = reset($this->errors);
        return is_array($firstError) ? reset($firstError) : $firstError;
    }

    public function getRemainingCredit(): ?int
    {
        return $this->getResponseValue('yourCredit') ??
            $this->getResponseValue('remainingCredit');
    }

    public function getRequiredCredit(): ?int
    {
        return $this->getResponseValue('requiredCredit');
    }

    public function getRateLimitWaitMinutes(): ?int
    {
        return $this->getResponseValue('waitMinutes');
    }

    protected function messageContains(string $keyword): bool
    {
        return str_contains(strtolower($this->getMessage()), $keyword);
    }

    protected function hasResponseKey(string $key): bool
    {
        return isset($this->responseData[$key]);
    }

    protected function getResponseValue(string $key, mixed $default = null): mixed
    {
        return $this->responseData[$key] ?? $default;
    }
}
