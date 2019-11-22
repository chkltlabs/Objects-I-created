<?php

namespace App\Models;

class ReportRollupAdless extends ReportRollupBase
{
    public $fillable = [
        'created_at',
        'post_settings_id',
        'submits',
        'accepted',
        'rejected',
        'duplicates',
        'accept_percent',
        'revenue',
    ];
    public $dates = ['created_at', 'updated_at'];
    public static $offsets = ['post_settings_id',];
    public static $adds = [
        'submits',
        'accepted',
        'rejected',
        'duplicates',
        'revenue',
    ];
    public static $calcs = [
        'accept_percent' => ['accepted', parent::OPER_DIVIDE, 'submits'],
    ];

    //------------------------------------------------------------------------------
    // Population Function
    //------------------------------------------------------------------------------

    public static function populate($startTime, $endTime)
    {
        $responseCollection = PostResponse::with('setting')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->whereNotNull('ad_id')
            ->where('ad_id', '==', 0)
            ->groupBy('post_settings_id')
            ->get();
        foreach ($responseCollection as $response) {
            //calculate values for each row
            $attempts = PostResponse::dateRange($startTime,
                $endTime)->withAll()->getAttemptsTwo($response->post_settings_id, 0);
            $attributes = [
                'created_at' => $startTime,
                'post_settings_id' => $response->post_settings_id,
                'submits' => $attempts['attempts'],
                'accepted' => $attempts['accepted'],
                'rejected' => $attempts['rejected'],
                'duplicates' => $attempts['duplicates'],
                'revenue' => $attempts['revenue'],
            ];
            $attributes = self::addCalculatedValues($attributes);
            self::createAndUpdate($attributes);
        }

    }

    //------------------------------------------------------------------------------
    // Eloquent Relationships
    //------------------------------------------------------------------------------

    public function setting()
    {
        return $this->belongsTo('App\Models\PostSetting');
    }
}
