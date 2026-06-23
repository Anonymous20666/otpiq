<?php

namespace Rstacode\Otpiq\Exceptions;

use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
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

        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $rawBody = $response->getBody()->getContents();
            $body = json_decode($rawBody, true);

            if (is_array($body)) {
                $responseData = $body;
                $message = $body['message'] ?? $body['error'] ?? $message;
                $errors = $body['errors'] ?? [];
            } else {
                $responseData = ['_raw_body' => $rawBody];
            }

            $code = $response->getStatusCode();
        } elseif ($e instanceof ConnectException) {
            $message = 'Connection failed: ' . $message;
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
        $message = strtolower($this->getMessage());
        return str_contains($message, 'credit') ||
            str_contains($message, 'insufficient') ||
            isset($this->responseData['yourCredit']);
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
        $message = strtolower($this->getMessage());
        return str_contains($message, 'trial mode');
    }

    public function isSpendingThresholdError(): bool
    {
        return isset($this->responseData['spendingThreshold']);
    }

    public function isSenderIdError(): bool
    {
        $message = strtolower($this->getMessage());
        return str_contains($message, 'senderid');
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
        return $this->responseData['yourCredit'] ??
            $this->responseData['remainingCredit'] ??
            null;
    }

    public function getRequiredCredit(): ?int
    {
        return $this->responseData['requiredCredit'] ?? null;
    }

    public function getRateLimitWaitMinutes(): ?int
    {
        return $this->responseData['waitMinutes'] ?? null;
    }

    public function isConnectionError(): bool
    {
        return $this->getPrevious() instanceof ConnectException;
    }

    public function isTimeoutError(): bool
    {
        if (!$this->isConnectionError()) {
            return false;
        }

        $message = strtolower($this->getMessage());
        return str_contains($message, 'timed out') ||
            str_contains($message, 'timeout');
    }

    public function isServerError(): bool
    {
        return $this->getCode() >= 500 && $this->getCode() < 600;
    }
}
