<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A request that is well formed and permitted, but that the current state of the school
 * does not allow.
 *
 * "Activate this term" while another term is active, or "delete the current session", are
 * neither malformed input nor authorization failures, so a Form Request rule cannot express
 * them and a 422 from validation would be misleading about the cause. Services throw this
 * instead, and it is rendered as a 422 with a message that names the actual obstacle, so
 * the client learns what to change rather than that something was wrong.
 *
 * The 422 status is used for every state conflict in this module on purpose. 409 Conflict
 * would be defensible for some of these, but a client that has to special case the status
 * between otherwise identical rejections gains nothing: in every case the remedy is the
 * same, which is to bring the school into a state where the request makes sense.
 */
class BusinessRuleViolation extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  optional per-field detail
     */
    public function __construct(
        string $message,
        protected array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
