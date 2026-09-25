<?php

namespace KobaltDigital\PingPong\Scheduling;

enum CheckInSignal: string
{
    case START = 'start';
    case SUCCESS = 'success';
    case FAIL = 'fail';
    case SKIPPED = 'skipped';
}
