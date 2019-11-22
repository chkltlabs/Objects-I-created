<?php

namespace App\Models;

class ReportRollupDistribution extends ReportRollupBase
{
    public $fillable = [
        'created_at',
        'advertiser_id',
        'ad_id',
        'post_settings_id',
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
    public static $offsets = ['advertiser_id', 'ad_id', 'post_settings_id',];
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
        $responseCollection = PostResponse::with('setting', 'setting.post', 'setting.post.advertiser', 'ad')
            ->join('post_settings', 'post_settings.id', '=', 'post_responses.post_settings_id')
            ->whereBetween('post_responses.created_at', [$startTime, $endTime])
            ->whereNotNull('ad_id')
            ->where('post_settings.type', '=', PostSetting::TYPE_POST)
            ->where('ad_id', '!=', 0)
            ->groupBy('post_settings_id')
            ->orderBy('ad_id')
            ->get();
        foreach ($responseCollection as $response) {
            $attempts = PostResponse::dateRange($startTime, $endTime)
                ->withAll()
                ->getAttemptsTwo($response->post_settings_id, $response->ad_id);
            $impressions = Impression::dateRange($startTime, $endTime)
                ->where('ad_id', $response->ad_id)
                ->sum('impressions');
            $idArr = LeadAnswer::dateRange($startTime, $endTime)
                ->where('ad_id', $response->ad_id)
                ->pluck('lead_id')
                ->toArray();
            $uniqueUserCount = count(array_unique($idArr, SORT_REGULAR));

            $attributes = [
                'created_at' => $startTime,
                'advertiser_id' => $response->ad->advertiser->id,
                'ad_id' => $response->ad_id,
                'post_id' => $response->setting->post->id,
                'post_settings_id' => $response->setting->id,
                'unique_users' => $uniqueUserCount ?? 0,
                'impressions' => $impressions ?? 0,
                'submits' => $attempts['attempts'],
                'accepted' => $attempts['accepted'],
                'rejected' => $attempts['rejected'],
                'duplicates' => $attempts['duplicates'],
                'revenue' => $attempts['revenue'],
            ];
            $attributes = self::addCalculatedValues($attributes);
            $oldAttributes = self::getCurrentAttributes($attributes);
            if (self::ecpmHasChangedBy($attributes, $oldAttributes)) {
                self::sendNotification('ecpm', $attributes, $oldAttributes);
            }
            self::createAndUpdate($attributes);
            self::setCurrentEcpm($attributes);
        }
    }

    public static function setCurrentEcpm($attrs)
    {
        $ad = Ad::find($attrs['ad_id']);
        if (!$ad) {
            return 0;
        }
        $displayData = self::getDisplayDataForDates('yesterday', 'today', ['ad_id' => $attrs['ad_id']]);
        if (!$displayData) {
            return 1;
        }
        $dataArr = [];
        foreach ($displayData as $row) {
            foreach (self::$adds as $add) {
                if (!isset($dataArr[$add])) {
                    $dataArr[$add] = 0;
                }
                $dataArr[$add] += $row[$add];
            }
        }
        $dataArr = self::addCalculatedValues($dataArr);
        $ad->current_ecpm = $dataArr['ecpm'];
        $ad->save();
        return $dataArr['ecpm'];
    }

    //------------------------------------------------------------------------------
    // Eloquent Relationships
    //------------------------------------------------------------------------------

    public function advertiser()
    {
        return $this->belongsTo('App\Models\Advertiser');
    }

    public function ad()
    {
        return $this->belongsTo('App\Models\Ad');
    }

    public function post()
    {
        return $this->belongsTo('App\Models\Post');
    }

    public function setting()
    {
        return $this->belongsTo('App\Models\PostSetting');
    }

}
