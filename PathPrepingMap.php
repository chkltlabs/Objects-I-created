<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PathPrepingMap extends Model
{
    protected $fillable = ['path_id', 'post_settings_id'];
    protected $dates = ['created_at', 'updated_at'];

    public static function updateAllPaths(){
        $paths = Path::all();
        foreach($paths as $path){
            self::createAndUpdate($path->id);
        }
    }

    public static function getPrepingsFromPath($pathId)
    {
        //plucks an array of question ids
        $questionIds = PathFlow::with('questions')
            ->where('path_id', $pathId)
            ->whereNull('deleted_at')
            ->get()
            ->pluck('position_id');
        $answers = [];
        foreach ($questionIds as $qid) {
            //each iteration plucks an array of answer IDs and appends that array to a growing array of answers
            $answers = array_merge($answers, Answer::where('model_type', 'App\\Models\\Question')
                ->where('model_id', $qid)
                ->whereNull('deleted_at')
                ->get()
                ->pluck('id')
                ->toArray()
            );
        }
        $settingIds = [];
        $adIds = [];
        foreach ($answers as $aid) {
            //finds settingIds for implicit offers - PostSettings tied directly to questions
            $settingIds = array_merge($settingIds, AnswerAction::where('model_type', 'App\\Models\\PostSetting')
                ->whereNull('deleted_at')
                ->where('answer_id', $aid)
                ->get()
                ->pluck('model_id')
                ->toArray()
            );
            //plucks an array of ad IDs related to the previous array of answer IDs
            $adIds = array_merge($adIds, AnswerAction::where('model_type', 'App\\Models\\Ad')
                ->whereNull('deleted_at')
                ->where('answer_id', $aid)
                ->get()
                ->pluck('model_id')
                ->toArray()
            );
        }
        $adAnswerIds = [];
        foreach ($adIds as $adId) {
            //plucks an array of Answer IDs from the previous array of Ad IDs
            $adAnswerIds = array_merge($adAnswerIds, Answer::where('model_type', 'App\\Models\\Ad')
                ->where('model_id', $adId)
                ->whereNull('deleted_at')
                ->get()
                ->pluck('id')
                ->toArray()
            );
        }
        foreach ($adAnswerIds as $adAnswerId) {
            //adds PostSetting IDs to the previous array used for the implicit PostSettings
            $settingIds = array_merge($settingIds, AnswerAction::where('model_type', 'App\\Models\\PostSetting')
                ->whereNull('deleted_at')
                ->where('answer_id', $adAnswerId)
                ->get()
                ->pluck('model_id')
                ->toArray()
            );
        }
        $verificationArray = PathVerification::getPostSettingsForPath($pathId);
        $settingIds = array_merge($settingIds, $verificationArray);
        $pingIds = [];
        foreach ($settingIds as $sid) {
            $setting = PostSetting::find($sid);
            $postId = $setting->post_id;
            $pingObj = PostSetting::find($setting->ping_id);
            if ($setting && $pingObj && $setting->ping_id) {
                //if find() finds a PostSetting and that PostSetting has a ping_id set, we add it to the output array and trigger the next step in the loop
                $pingIds[] = $setting->ping_id;
                continue;
            }
            //if we did not satisfy the previous conditional, manually find the PostSetting for preping associated with that post_id
            $setting2 = PostSetting::where('post_id', $postId)
                ->where('type', PostSetting::TYPE_PREPING)
                ->first();
            //if we found a setting with the second method, add its ID and trigger the next step in the loop
            if ($setting2 && $setting2->id) {
                $pingIds[] = $setting2->id;
                continue;
            }
        }


        return array_unique($pingIds);
    }

    public static function getPingsArray($pathId)
    {
        return self::where('path_id', $pathId)
            ->get()
            ->pluck('post_settings_id')
            ->toArray();
    }

    public static function createAndUpdate($pathId)
    {
        $pingIds = self::getPrepingsFromPath($pathId);
        $existing = self::getPingsArray($pathId);
        //deletion loop
        foreach ($existing as $key => $ex) {
            if (!in_array($ex, $pingIds)) {
                $thing = self::where('path_id', $pathId)->where('post_settings_id', $ex)->first();
                $thing->delete();
                unset($existing[$key]);
            }
        }
        //creation loop
        foreach ($pingIds as $pid) {
            if (!in_array($pid, $existing)) {
                $attrs = [
                    'path_id' => $pathId,
                    'post_settings_id' => $pid,
                ];
                self::create($attrs);
            }
        }
    }
}
