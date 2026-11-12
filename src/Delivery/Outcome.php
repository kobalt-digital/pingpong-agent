<?php

namespace KobaltDigital\PingPong\Delivery;

enum Outcome
{
    case DELIVERED;

    /**
     * PingPong answered, but refused the request. Sending it again changes
     * nothing.
     */
    case REJECTED;

    /**
     * PingPong could not be reached, failed or asked to slow down, so the
     * same request may land later.
     */
    case UNDELIVERED;

    /**
     * The Agent is off, so nothing was sent.
     */
    case SKIPPED;
}
