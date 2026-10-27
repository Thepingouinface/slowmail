<?php

use Illuminate\Support\Facades\Schedule;

// Chaque minute, un passage : tire le plan du jour, puis envoie au plus un mail dû, directement (pas de file).
// withoutOverlapping : jamais deux passages en même temps ; la réclamation atomique de la ligne le garantit aussi.
Schedule::command('slowmail:tick')->everyMinute()->withoutOverlapping(10);
