<?php

namespace Rstacode\Otpiq;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Rstacode\Otpiq\Exceptions\OtpiqApiException;

class OtpiqService
{
    protected Client $client;
    protected string $apiKey;
    protected string $baseUrl;

    private const VALID_SMS_TYPES = [
        'verification',
        'custom',
        'whatsapp-template',
    ];

    private const VALID_PROVIDERS = [
        'auto',
        'whatsapp-sms',
        'telegram-sms',
        'whatsapp-telegram-sms',
        'sms',
        'whatsapp',
        'telegram',
    ];

    public function __construct(string $apiKey, string $baseUrl = 'https://api.otpiq.com/api/')
    {
        if (empty(trim($apiKey))) {
            throw new InvalidArgumentException(
                'OTPIQ API key is required. Set the OTPIQ_API_KEY environment variable.'
            );
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => config('otpiq.timeout', 30),
        ]);
    }

    public function getProjectInfo(): array
    {
        return $this->request('GET', 'info');
    }

    public function sendSms(array $data): array
    {
        $this->validateSmsData($data);

        return $this->request('POST', 'sms', $data);
    }

    public function getSenderIds(): array
    {
        return $this->request('GET', 'sender-ids');
    }

    public function trackSms(string $smsId): array
    {
        if (empty(trim($smsId)) || !preg_match('/^[a-zA-Z0-9\-_]+$/', $smsId)) {
            throw new InvalidArgumentException(
                'Invalid SMS ID format. SMS ID must contain only alphanumeric characters, hyphens, and underscores.'
            );
        }

        return $this->request('GET', 'sms/track/' . urlencode($smsId));
    }

    protected function validateSmsData(array $data): void
    {
        if (empty($data['phoneNumber'])) {
            throw new InvalidArgumentException('Phone number is required.');
        }

        if (!preg_match('/^\d{10,15}$/', $data['phoneNumber'])) {
            throw new InvalidArgumentException(
                'Invalid phone number format. Must be 10-15 digits.'
            );
        }

        if (empty($data['smsType'])) {
            throw new InvalidArgumentException('SMS type is required.');
        }

        if (!in_array($data['smsType'], self::VALID_SMS_TYPES, true)) {
            throw new InvalidArgumentException(
                'Invalid SMS type. Must be one of: ' . implode(', ', self::VALID_SMS_TYPES)
            );
        }

        if (isset($data['provider']) && !in_array($data['provider'], self::VALID_PROVIDERS, true)) {
            throw new InvalidArgumentException(
                'Invalid provider. Must be one of: ' . implode(', ', self::VALID_PROVIDERS)
            );
        }

        if ($data['smsType'] === 'verification' && empty($data['verificationCode'])) {
            throw new InvalidArgumentException(
                'Verification code is required when smsType is "verification".'
            );
        }

        if ($data['smsType'] === 'custom' && empty($data['customMessage'])) {
            throw new InvalidArgumentException(
                'Custom message is required when smsType is "custom".'
            );
        }

        if ($data['smsType'] === 'whatsapp-template') {
            if (empty($data['templateName'])) {
                throw new InvalidArgumentException(
                    'Template name is required when smsType is "whatsapp-template".'
                );
            }
            if (empty($data['whatsappAccountId'])) {
                throw new InvalidArgumentException(
                    'WhatsApp account ID is required when smsType is "whatsapp-template".'
                );
            }
            if (empty($data['whatsappPhoneId'])) {
                throw new InvalidArgumentException(
                    'WhatsApp phone ID is required when smsType is "whatsapp-template".'
                );
            }
        }

        if (isset($data['deliveryReport'])) {
            $this->validateDeliveryReport($data['deliveryReport']);
        }
    }

    protected function validateDeliveryReport(mixed $report): void
    {
        if (!is_array($report)) {
            throw new InvalidArgumentException('Delivery report must be an array.');
        }

        if (isset($report['webhookUrl']) && !filter_var($report['webhookUrl'], FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Delivery report webhook URL must be a valid URL.');
        }

        if (isset($report['webhookUrl'])) {
            $scheme = parse_url($report['webhookUrl'], PHP_URL_SCHEME);
            if (!in_array($scheme, ['https', 'http'], true)) {
                throw new InvalidArgumentException('Delivery report webhook URL must use HTTP or HTTPS.');
            }
        }

        if (isset($report['deliveryReportType']) && !in_array($report['deliveryReportType'], ['all', 'final'], true)) {
            throw new InvalidArgumentException(
                'Delivery report type must be "all" or "final".'
            );
        }
    }

    protected function request(string $method, string $uri, array $data = []): array
    {
        try {
            $options = $method === 'POST' && !empty($data) ? ['json' => $data] : [];
            $response = $this->client->request($method, $uri, $options);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (GuzzleException $e) {
            throw OtpiqApiException::fromGuzzleException($e);
        }
    }
}
