<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The stable identifier that travels beside every error message.
 *
 * A client should never have to read prose to decide what to do: messages get
 * reworded, translated and improved, while a code stays put. It is derived from
 * the status so the two can never contradict each other — and when two
 * different failures eventually need to share a status, this is the single
 * place where they get told apart.
 */
final class ErrorCode
{
    public static function forStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            422 => 'validation_failed',
            429 => 'too_many_requests',
            default => $status >= 500 ? 'server_error' : 'client_error',
        };
    }
}
