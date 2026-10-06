<?php

namespace App\Services;

use App\Models\AiSetting;
use App\Models\Lead;

class LeadScoringService
{
    public function calculate(Lead $lead): array
    {
        $weights = AiSetting::firstOrFail()->scoring;
        $breakdown = [];
        foreach ($weights as $field => $weight) {
            $present = in_array($field, ['buying', 'appointment', 'returning', 'pricing'], true) ? ($lead->signals[$field] ?? false) : filled($lead->getAttribute($field));
            if ($present) {
                $breakdown[$field] = max(0, min(100, (int) $weight));
            }
        }
        $score = min(100, array_sum($breakdown));
        $temperature = match (true) {
            $score >= 80 => 'very_hot',$score >= 60 => 'hot',$score >= 30 => 'warm',default => 'cold'
        };

        return compact('score', 'temperature', 'breakdown');
    }
}
