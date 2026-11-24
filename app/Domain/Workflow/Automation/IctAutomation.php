<?php

namespace App\Domain\Workflow\Automation;

use App\Models\Booking;

/** An automation of one ICT type (e.g. a software install pushed by a deployment system): runs on a request waiting in the queue. */
interface IctAutomation
{
    /** true = the work is done and the request moves on (Transition::AUTO_AUTOMATION); false = it stays for a representative. */
    public function run(Booking $booking): bool;
}
