<?php

namespace App\Models;

class ReportRollupQuestionAnswer extends ReportRollupBase
{
    public $fillable = [
        'created_at',
        'model_id',
        'model_type',
        'model_text',
        'parent_id',
        'parent_type',
        'path_id',
        'impressions',
        'conversions',
        'revenue',
        'rev_per_impression',
        'ecpm'
    ];
    public $dates = ['created_at', 'updated_at'];
    public static $offsets = [
        'model_id',
        'model_type',
        'model_text',
        'parent_id',
        'parent_type',
        'path_id',
    ];
    public static $adds = ['impressions', 'conversions', 'revenue'];
    public static $calcs = [
        'rev_per_impression' => ['revenue', parent::OPER_DIVIDE, 'impressions'],
        'ecpm' => ['rev_per_impression', parent::OPER_MULT, 1000],
    ];

    //------------------------------------------------------------------------------
    // Population Function
    //------------------------------------------------------------------------------

    public static function populate($startTime, $endTime)
    {
        //find all questions that were answered in the given timeframe
        $questionIdArray = LeadAnswer::whereBetween('created_at', [$startTime, $endTime])
            ->whereNull('deleted_at')
            ->groupBy('question_id')
            ->distinct()
            ->select('question_id')
            ->get()
            ->pluck('question_id')
            ->toArray();
        $questionArray = Question::whereIn('id', $questionIdArray)->chunk(1,
            function ($questionArray) use ($startTime, $endTime) {
                foreach ($questionArray as $question) {
                    $conversions = 0;
                    $revenue = 0.00;
                    //create basic question row so that it occurs first.
                    $attributes = [
                        'created_at' => $startTime,
                        'model_id' => $question->id,
                        'model_type' => 'App\\Models\\Question',
                        'model_text' => strip_tags($question->question),
                        'parent_id' => null,
                        'parent_type' => null,
                        'path_id' => $question->path_id,
                    ];
                    $questionRow = self::createAndUpdate($attributes);
                    foreach ($question->answers as $answer) {
                        $answerConversions = 0;
                        $answerRevenue = 0.00;
                        //get array of lead_ids that have hit this answer. This will be used to find Clicks and PostResponses in the next loop.
                        $leadIdArray = LeadAnswer::whereBetween('created_at', [$startTime, $endTime])
                            ->where('question_id', $question->id)
                            ->where('answer_id', $answer->id)
                            ->where('path_id', $question->path_id)
                            ->select('lead_id')
                            ->distinct()
                            ->get()
                            ->pluck('lead_id')
                            ->toArray();
                        $answer = $answer->load('answerActions');
                        foreach ($answer->answerActions as $action) {
                            //loop through answer actions totalling conversions(PostResponse::SUCCESS count or Click Conversions) and revenue
                            switch ($action->model_type) {
                                case 'App\Models\Link':
                                    $answerConversions += Click::whereIn('lead_id', $leadIdArray)
                                        ->where('link_id', $action->model_id)
                                        ->where('path_id', $question->path_id)
                                        ->whereBetween('created_at', [$startTime, $endTime])
                                        ->select('converted')
                                        ->sum('converted');
                                    $answerRevenue += Click::whereIn('lead_id', $leadIdArray)
                                        ->where('link_id', $action->model_id)
                                        ->where('path_id', $question->path_id)
                                        ->whereBetween('created_at', [$startTime, $endTime])
                                        ->select('revenue')
                                        ->sum('revenue');
                                    break;
                                case 'App\Models\PostSetting':
                                    $answerConversions += PostResponse::whereIn('lead_id', $leadIdArray)
                                        ->where('post_settings_id', $action->model_id)
                                        ->where('status', PostResponse::SUCCESS)
                                        ->where('path_id', $question->path_id)
                                        ->whereBetween('created_at', [$startTime, $endTime])
                                        ->select('id')
                                        ->count();
                                    $answerRevenue += PostResponse::whereIn('lead_id', $leadIdArray)
                                        ->where('post_settings_id', $action->model_id)
                                        ->where('path_id', $question->path_id)
                                        ->whereBetween('created_at', [$startTime, $endTime])
                                        ->select('revenue')
                                        ->sum('revenue');
                                    break;
                                case 'App\Models\Ad':
                                    $ad = Ad::find($action->model_id);
                                    if (!$ad) {
                                        break;
                                    }
                                    foreach ($ad->answers as $adAnswer) {
                                        //get array of lead_ids that have hit this answer. This will be used to find Clicks and PostResponses in the next loop.
                                        $leadIdArray = LeadAnswer::whereBetween('created_at', [$startTime, $endTime])
                                            ->where('ad_id', $ad->id)
                                            ->where('answer_id', $adAnswer->id)
                                            ->where('path_id', $question->path_id)
                                            ->select('lead_id')
                                            ->distinct()
                                            ->get()
                                            ->pluck('lead_id')
                                            ->toArray();
                                        $adAnswer = $adAnswer->load('answerActions');
                                        foreach ($adAnswer->answerActions as $adAction) {
                                            //loop through answer actions totalling conversions(PostResponse::SUCCESS count or Click Conversions) and revenue
                                            switch ($adAction->model_type) {
                                                case 'App\Models\Link':
                                                    $answerConversions += Click::whereIn('lead_id', $leadIdArray)
                                                        ->where('link_id', $adAction->model_id)
                                                        ->where('path_id', $question->path_id)
                                                        ->whereBetween('created_at', [$startTime, $endTime])
                                                        ->select('converted')
                                                        ->sum('converted');
                                                    $answerRevenue += Click::whereIn('lead_id', $leadIdArray)
                                                        ->where('link_id', $adAction->model_id)
                                                        ->where('path_id', $question->path_id)
                                                        ->whereBetween('created_at', [$startTime, $endTime])
                                                        ->select('revenue')
                                                        ->sum('revenue');
                                                    break;
                                                case 'App\Models\PostSetting':
                                                    $answerConversions += PostResponse::whereIn('lead_id', $leadIdArray)
                                                        ->where('post_settings_id', $adAction->model_id)
                                                        ->where('status', PostResponse::SUCCESS)
                                                        ->where('path_id', $question->path_id)
                                                        ->whereBetween('created_at', [$startTime, $endTime])
                                                        ->select('id')
                                                        ->count();
                                                    $answerRevenue += PostResponse::whereIn('lead_id', $leadIdArray)
                                                        ->where('post_settings_id', $adAction->model_id)
                                                        ->where('path_id', $question->path_id)
                                                        ->whereBetween('created_at', [$startTime, $endTime])
                                                        ->select('revenue')
                                                        ->sum('revenue');
                                                    break;
                                                default:
                                                    break;
                                            }
                                        }
                                    }
                                    break;
                                default:
                                    break;
                            }
                        }

                        //create row for Answer
                        $attributes = [
                            'created_at' => $startTime,
                            'model_id' => $answer->id,
                            'model_type' => 'App\\Models\\Answer',
                            'model_text' => strip_tags($answer->answer),
                            'parent_id' => $question->id,
                            'parent_type' => 'App\\Models\\Question',
                            'path_id' => $question->path_id,
                            'impressions' => (integer)AnswerImpression::where('answer_id', $answer->id)
                                ->where('path_id', $question->path_id)
                                ->whereBetween('created_at', [$startTime, $endTime])
                                ->sum('impressions'),
                            'conversions' => $answerConversions,
                            'revenue' => $answerRevenue,
                        ];
                        $attributes = self::addCalculatedValues($attributes);
                        self::createAndUpdate($attributes);
                        $conversions += $answerConversions;
                        $revenue += $answerRevenue;

                    }
                    //create row for question
                    $attributes = [
                        'created_at' => $startTime,
                        'model_id' => $question->id,
                        'model_type' => 'App\\Models\\Question',
                        'model_text' => strip_tags($question->question),
                        'parent_id' => null,
                        'parent_type' => null,
                        'path_id' => $question->path_id,
                        'impressions' => (integer)QuestionImpression::where('question_id', $question->id)
                            ->where('path_id', $question->path_id)
                            ->whereBetween('created_at', [$startTime, $endTime])
                            ->sum('impressions'),
                        'conversions' => $conversions,
                        'revenue' => $revenue,
                    ];
                    $attributes = self::addCalculatedValues($attributes);
                    self::createAndUpdate($attributes);
                }
            });
    }

    //------------------------------------------------------------------------------
    // Eloquent Relationships
    //------------------------------------------------------------------------------


}
