<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A manager's improvement report for one month: what went well, what is wrong, what to
 * improve and what they need. One per manager per month; the manager may revise it
 * during the month, and it is kept for good.
 */
#[Fillable(['agent', 'month', 'title', 'body'])]
class MonthlyReport extends Model
{
    public function monthName(): string
    {
        return Carbon::createFromFormat('Y-m', $this->month)->format('F Y');
    }
}
