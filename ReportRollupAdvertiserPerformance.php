<?php

namespace App\Models;

class ReportRollupAdvertiserPerformance extends ReportRollupBase
{
    public $fillable = [
        'created_at',
        'advertiser_id',
        'post_id',
        'submits',
        'accepts',
        'rejects',
        'duplicates',
        'revenue',
        'cpl',
    ];

    public $dates = ['created_at', 'updated_at'];
    public static $offsets = ['advertiser_id', 'post_id'];
    public static $adds = [
        'submits',
        'accepts',
        'rejects',
        'duplicates',
        'revenue',
    ];
    public static $calcs = [
        'cpl' => ['revenue', parent::OPER_DIVIDE, 'accepts']
    ];

    //------------------------------------------------------------------------------
    // Population Function
    //------------------------------------------------------------------------------

    public static function populate($startTime, $endTime)
    {
        foreach (Advertiser::all() as $advertiser) {
            if ($advertiser->key == null) {
                $advertiser->key = PostResponse::generateKey();
                $advertiser->save();
            }
            foreach ($advertiser->posts as $post) {
                $postSetting = $post->setting()->where('type', '!=', PostSetting::TYPE_PREPING)->first();
                if (!$postSetting) {
                    continue;
                }
                $attempts = PostResponse::dateRange($startTime, $endTime)->withAll()->getAttemptsTwo($postSetting->id);
                $attributes = [
                    'created_at' => $startTime,
                    'advertiser_id' => $advertiser->id,
                    'post_id' => $post->id,
                    'submits' => $attempts['attempts'],
                    'accepts' => $attempts['accepted'],
                    'rejects' => $attempts['rejected'],
                    'duplicates' => $attempts['duplicates'],
                    'revenue' => $attempts['revenue'],
                ];
                $attributes = self::addCalculatedValues($attributes);
                self::createAndUpdate($attributes);
            }
        }
    }
}
