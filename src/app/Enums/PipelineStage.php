<?php

namespace App\Enums;

enum PipelineStage: string
{
    case Lead = 'lead';
    case Pitched = 'pitched';
    case Negotiating = 'negotiating';
    case Onboarded = 'onboarded';
    case Active = 'active';
    case RepeatClient = 'repeat-client';
    case Lost = 'lost';

    public static function options(): array
    {
        return array_map(fn (self $stage) => [
            'value' => $stage->value,
            'label' => match ($stage) {
                self::RepeatClient => 'Repeat Client',
                default => ucfirst($stage->value),
            },
        ], self::cases());
    }
}
