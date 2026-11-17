<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for generations whose webhooks never arrived: poll Pixelforge directly.
Schedule::command('pixelforge:sync')->everyMinute();
