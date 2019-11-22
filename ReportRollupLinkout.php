<?php

namespace App\Models;

class ReportRollupLinkout extends ReportRollupBase
{
    public $fillable = [
        'created_at',
        'advertiser_id',
        'ad_id',
        'path_id',
        'position',
        'lead_count',
        'impressions',
        'unique_impressions',
        'clicks',
        'unique_clicks',
        'conversions',
        'revenue',
        'conversion_rate_percent',
        'epc',
        'rev_per_impression',
        'ecpm',
    ];
    public $dates = ['created_at', 'updated_at'];
    public static $offsets = ['advertiser_id', 'ad_id', 'path_id', 'position',];
    public static $adds = [
        'impressions',
        'unique_impressions',
        'clicks',
        'unique_clicks',
        'conversions',
        'revenue',
    ];
    public static $calcs = [
        'conversion_rate_percent' => ['lead_count', parent::OPER_DIVIDE, 'unique_impressions'],
        'epc' => ['revenue', parent::OPER_DIVIDE, 'clicks'],
        'rev_per_impression' => ['revenue', parent::OPER_DIVIDE, 'impressions'],
        'ecpm' => ['rev_per_impression', parent::OPER_MULT, 1000],
    ];

    //------------------------------------------------------------------------------
    // Population Function
    //------------------------------------------------------------------------------

    public static function populate($startTime, $endTime)
    {
        $responseCollection = Click::with('ad', 'ad.advertiser', 'link', 'link.answerAction')
            ->dateRange($startTime, $endTime)
            ->whereNotNull('ad_id')
            ->whereNotNull('path_id')
            ->whereNotNull('path_position')
            ->where('ad_id', '!=', 0)
            ->groupBy('path_position')
            ->groupBy('ad_id')
            ->groupBy('path_id')
            ->orderBy('ad_id')
            ->orderBy('path_id')
            ->orderBy('path_position')
            ->get();
        foreach ($responseCollection as $click) {
            $baseBuilder = Click::dateRange($startTime, $endTime)
                ->where('ad_id', $click->ad_id)
                ->where('path_id', $click->path_id)
                ->where('path_position', $click->path_position);
            $leadsArr = $baseBuilder
                ->pluck('lead_id')
                ->toArray();
            $impressions = Impression::dateRange($startTime, $endTime)
                ->whereNotNull('ad_id')
                ->where('ad_id', '=', $click->ad->id)
                ->where('path_id', $click->path_id)
                ->sum('impressions');
            $uniqueImpressions = Impression::dateRange($startTime, $endTime)
                ->whereNotNull('ad_id')
                ->where('ad_id', '=', $click->ad->id)
                ->where('path_id', $click->path_id)
                ->count();
            $clickCount = $baseBuilder
                ->sum('clicks');
            $clickUniqueCount = $baseBuilder
                ->count();
            $leadCount = count(array_unique($leadsArr));
            $conversions = $baseBuilder
                ->whereNotNull('converted_at')
                ->count();
            $revenue = $baseBuilder
                ->sum('revenue');
            $attributes = [
                'created_at' => $startTime,
                'advertiser_id' => $click->ad->advertiser->id,
                'ad_id' => $click->ad->id,
                'path_id' => $click->path_id,
                'position' => $click->path_position,
                'lead_count' => $leadCount ?? 0,
                'impressions' => $impressions ?? 0,
                'unique_impressions' => $uniqueImpressions ?? 0,
                'clicks' => $clickCount ?? 0,
                'unique_clicks' => $clickUniqueCount ?? 0,
                'conversions' => $conversions ?? 0,
                'revenue' => $revenue ?? 0,
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
        $pathFlowRow = PathFlow::where('path_id', $attrs['path_id'])
            ->where('position_id', $attrs['ad_id'])
            ->where('position_type', 'App\Models\Ad')
            ->first();
        if (!$pathFlowRow) {
            return 0;
        }
        $displayData = self::getDisplayDataForDates('yesterday', 'today',
            ['ad_id' => $attrs['ad_id'], 'path_id' => $attrs['path_id']]);
        if (empty($displayData)) {
            return 0;
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
        $pathFlowRow->current_ecpm = $dataArr['ecpm'];
        $pathFlowRow->save();
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

    public function path()
    {
        return $this->belongsTo('App\Models\Path');
    }
}
