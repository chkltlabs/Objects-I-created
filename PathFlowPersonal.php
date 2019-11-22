<?php

namespace App\Models;

use App\Services\Leads\PriorityService;
use Illuminate\Database\Eloquent\Model;

class PathFlowPersonal extends Model
{
    public $table = 'path_flow_personals';

    public $fillable = ['lead_id', 'position_id', 'position_type', 'path_id', 'order', 'is_linkout'];

    public static function createOrUpdate($attributes)
    {
        $existingRow = self::where('lead_id', $attributes['lead_id'])
            ->where('path_id', $attributes['path_id'])
            ->where('position_type', $attributes['position_type'])
            ->where('position_id', $attributes['position_id'])
            ->first();
        if (!$existingRow) {
            self::create($attributes);
        } else {
            $existingRow->update($attributes);
        }
    }

    public static function reprioritize($lead_id, $path_id)
    {
        //because this is in a 'scalable' space, we dont want to spend resources if we dont have to. Hence, all the early return conditions.
        //get all linkout rows
        $currentLinkoutCollection =
            self::where('path_id', $path_id)
                ->where('lead_id', $lead_id)
                ->where('is_linkout', 1)
                ->orderBy('order', 'ASC')
                ->get();
        $possibleLinkoutCollection =
            PathFlow::where('path_id', $path_id)
                ->where('is_linkout', 1)
                ->orderBy('order', 'ASC')
                ->get();
        if (!$currentLinkoutCollection || !isset($currentLinkoutCollection) || $currentLinkoutCollection->isEmpty()) {
            return;
        }
        //get current order value for the first position
        $firstPosition = $currentLinkoutCollection[0]->order;
        if (!$firstPosition) {
            return;
        }
        //get tags
        $lead = Lead::find($lead_id);
        if (!$lead) {
            return;
        }
        $tagsArray = $lead->tags;
        if (!$tagsArray) {
            return;
        }
        //delete old rows
        foreach ($currentLinkoutCollection as $cur) {
            $cur->delete();
        }
        //run prioritizer
        $ps = new PriorityService($possibleLinkoutCollection, $tagsArray);
        $newLinkoutCollection = $ps->prioritize();
        //loop through in new order, updating with createOrUpdate
        $unlimited = false;
        $pathLinkoutLimit = Path::where('id', $path_id)->select('linkout_limit')->limit(1)->first()->linkout_limit;
        if ($pathLinkoutLimit === 0 || $pathLinkoutLimit === null) {
            $unlimited = true;
        }
        foreach ($newLinkoutCollection as $new) {
            $attributes = $new->getAttributes();
            $attributes['order'] = $firstPosition;
            $attributes['lead_id'] = $lead_id;
            self::createOrUpdate($attributes);
            $firstPosition++;
            if (!$unlimited) {
                $pathLinkoutLimit--;
                if ($pathLinkoutLimit === 0) {
                    break;
                }
            }
        }
    }

    public static function dropPersonalPath($lead_id, $path_id)
    {
        $deletables = self::where('lead_id', $lead_id)->where('path_id', $path_id)->get();
        if (!$deletables) {
            return;
        }
        foreach ($deletables as $d) {
            $d->delete();
        }
    }

    public function position()
    {
        return $this->morphTo();
    }

    public function path()
    {
        return $this->hasOne('App/Models/Path', 'id', 'path_id');
    }

    public function question()
    {
        return $this->morphOne('App\Models\Question', 'position');
    }

    public function ad()
    {
        return $this->morphOne('App\Models\Ad', 'position');
    }
}
