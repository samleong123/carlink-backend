<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AiProviderUnavailable extends HttpException
{
    public const CAPACITY_MESSAGE = 'Please try again later due to Google Gemini capacity issue';

    public function __construct(
        string $message = self::CAPACITY_MESSAGE,
        int $statusCode = 502,
        public readonly string $reasonCode = 'GEMINI_CAPACITY_ISSUE',
    ) {
        parent::__construct($statusCode, $message);
    }
}
