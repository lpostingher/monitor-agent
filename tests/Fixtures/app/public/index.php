<?php

use Illuminate\Support\Facades\DB;

// Stands in for an application's front controller: framework code runs below it.
DB::select('select 1 as from_the_front_controller');
