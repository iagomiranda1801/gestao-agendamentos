<?php

namespace App\Support;

class PublicBookingTextSanitizer
{
    public const MAX_CLIENT_NAME = 255;

    public const MAX_CLIENT_NOTES = 500;

    public const MAX_CANCELLATION_REASON = 500;

    public const MAX_BOOKING_PAGE_TITLE = 120;

    public const MAX_BOOKING_PAGE_DESCRIPTION = 2000;

    public const MAX_BOOKING_CONFIRMATION_MESSAGE = 2000;

    public const MAX_PRIVACY_NOTICE = 5000;

    public const MAX_BOOKING_TERMS = 5000;

    public static function sanitize(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strip_tags($value);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
        $clean = trim($clean);

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, $maxLength);
    }

    public static function clientName(?string $value): ?string
    {
        return self::sanitize($value, self::MAX_CLIENT_NAME);
    }

    public static function clientNotes(?string $value): ?string
    {
        return self::sanitize($value, self::MAX_CLIENT_NOTES);
    }

    public static function cancellationReason(?string $value): ?string
    {
        return self::sanitize($value, self::MAX_CANCELLATION_REASON);
    }

    public static function bookingPageTitle(?string $value): ?string
    {
        return self::sanitize($value, self::MAX_BOOKING_PAGE_TITLE);
    }

    public static function bookingPageDescription(?string $value): ?string
    {
        return self::sanitize($value, self::MAX_BOOKING_PAGE_DESCRIPTION);
    }

    public static function bookingConfirmationMessage(?string $value): ?string
    {
        return self::sanitize($value, self::MAX_BOOKING_CONFIRMATION_MESSAGE);
    }

    public static function privacyNotice(?string $value): ?string
    {
        return self::sanitize($value, self::MAX_PRIVACY_NOTICE);
    }

    public static function bookingTerms(?string $value): ?string
    {
        return self::sanitize($value, self::MAX_BOOKING_TERMS);
    }
}
