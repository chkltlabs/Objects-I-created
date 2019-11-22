<?php

namespace App\Models;

class ReportRollupPath extends ReportRollupBase
{
    public $fillable = [
        'created_at',
        'path_id',
        'unique_users',
        'impressions',
        'submits',
        'accepted',
        'rejected',
        'duplicates',
        'accept_percent',
        'revenue',
        'rev_per_impression',
        'ecpm',
    ];
    public $dates = ['created_at', 'updated_at'];
    public static $offsets = ['path_id',];
    public static $adds = [
        'unique_users',
        'impressions',
        'submits',
        'accepted',
        'rejected',
        'duplicates',
        'revenue',
    ];
    public static $calcs = [
        'accept_percent' => ['accepted', parent::OPER_DIVIDE, 'submits'],
        'rev_per_impression' => ['revenue', parent::OPER_DIVIDE, 'impressions'],
        'ecpm' => ['rev_per_impression', parent::OPER_MULT, 1000],
    ];

    //------------------------------------------------------------------------------
    // Population Function
    //------------------------------------------------------------------------------

    public static function populate($startTime, $endTime)
    {
        $responseCollection = PostResponse::with('setting', 'setting.post', 'setting.post.advertiser', 'ad', 'path')
            ->join('post_settings', 'post_settings.id', '=', 'post_responses.post_settings_id')
            ->whereBetween('post_responses.created_at', [$startTime, $endTime])
            ->whereNotNull('ad_id')
            ->whereNotNull('path_id')
            ->where('post_settings.type', '=', PostSetting::TYPE_POST)
            ->where('ad_id', '!=', 0)
            ->groupBy('path_id')
            ->orderBy('path_id')
            ->get();
        foreach ($responseCollection as $response) {
            $attempts = PostResponse::dateRange($startTime,
                $endTime)->withAll()->getAttemptsForPath($response->path_id);
            $impressions = Impression::dateRange($startTime, $endTime)->where('path_id',
                $response->path_id)->sum('impressions');
            $idArr = LeadAnswer::dateRange($startTime, $endTime)->where('path_id',
                $response->path_id)->pluck('lead_id')->toArray();
            $uniqueUserCount = count(array_unique($idArr, SORT_REGULAR));
            $attributes = [
                'created_at' => $startTime,
                'path_id' => $response->path_id,
                'unique_users' => $uniqueUserCount ?? 0,
                'impressions' => $impressions ?? 0,
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

    public function post()
    {
        return $this->belongsTo('App\Models\Post');
    }
}
