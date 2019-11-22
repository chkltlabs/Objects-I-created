<?php

namespace App\Models;

use App\Jobs\SendNotificationEmail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

/**
 * Class ReportRollupBase
 * @package App\Models
 */
class ReportRollupBase extends Model
{
    const OPER_DIVIDE = '/';
    const OPER_MULT = '*';
    const OPER_SUB = '-';
    const OPER_ADD = '+';
    //fillable fields for the eloquent models
    public $fillable;
    //which fields eloquent will treat as dates
    public $dates;
    //which fields do not appear in the totals row. This is also used for locating existing matching rows
    public static $offsets;
    //which fields get simply added in the totals row
    public static $adds;
    //which fields require more complex operations to appear in the totals row,
    // and the operations they require, in a multidimensional array.
    //If your calculation requires multiple steps, make each a separate key in the highest
    //level array, and reference that key in your subsequent steps.
    // See App\Models\PathReportRollup for an example.
    public static $calcs;

    /**
     * This is only here so my IDE will quit yelling at me to implement it. Its never been necessary on any other model.
     *
     * @return array
     */
    public function getQueueableRelations()
    {
        return parent::getQueueableRelations();
    }

    public static function getDisplayDataForDates($start, $end, $where = [])
    {
        //we parse the passed in date strings, allowing for fun stuff like "yesterday" or "5 days ago" as well as basic time strings
        try {
            $startDate = Carbon::parse($start)->startOfDay()->toDateString();
            $endDate = Carbon::parse($end)->endOfDay()->toDateString();
        } catch (\Exception $e) {
            //if the date in question cant be parsed, we return null
            return null;
        }
        //build up an array of targets based on existing combinations of offsets values
        $uniqueTargets = self::whereBetween('created_at', [$startDate, $endDate]);
        //if $where has key-value pairs, and that key is in the offsets array, we limit the dataset to those parameters
        if (!empty($where)) {
            foreach ($where as $key => $value) {
                if (in_array($key, static::$offsets)) {
                    $uniqueTargets->where($key, $value);
                }
            }
        }
        $uniqueTargets = $uniqueTargets->select(static::$offsets)
            ->distinct()
            ->get()
            ->toArray();
        $output = [];
        foreach ($uniqueTargets as $rowNum => $targetAttrArray) {
            //instansiate a Builder for the same dates in question
            $baseBuilder = self::whereBetween('created_at', [$startDate, $endDate]);
            foreach ($targetAttrArray as $attr => $value) {
                //add constraints to the builder based on the current set of offsets, and also save those offsets to the output
                $output[$rowNum][$attr] = $value;
                $baseBuilder->where($attr, $value);
            }
            foreach (static::$adds as $add) {
                //execute the builder for the sum of each value in the $adds
                $output[$rowNum][$add] = $baseBuilder->sum($add);
            }
            //calculate $calcs values for that row and save the final result
            $output[$rowNum] = self::addCalculatedValues($output[$rowNum]);
        }
        return $output;
    }

    public static function addCalculatedValues($valuesArr)
    {
        foreach (static::$calcs as $thingToCalculate => $instructionArray) {
            //the instructionArray key must either be set on the incoming valuesArr, or be numeric to proceed with calculations. This check applies to positions 0 and 2.
            if ((!isset($valuesArr[$instructionArray[0]]) && !is_numeric($instructionArray[0]))
                || (!isset($valuesArr[$instructionArray[2]]) && !is_numeric($instructionArray[2]))) {
                continue;
            }
            //if the given instruction is an existing key on the valuesArr, set the operator equal to the found value for that key
            //if it is not found in the valuesArr, set the operator directly equal to the given instruction.
            $firstOperator = isset($valuesArr[$instructionArray[0]]) ? $valuesArr[$instructionArray[0]] : $instructionArray[0];
            $secondOperator = isset($valuesArr[$instructionArray[2]]) ? $valuesArr[$instructionArray[2]] : $instructionArray[2];
            switch ($instructionArray[1]) {//save result to valuesArr with $calcs key as valuesArr key
                case self::OPER_DIVIDE: //divide by zero protection
                    $valuesArr[$thingToCalculate] = $secondOperator == 0 ? 0 : $firstOperator / $secondOperator;
                    break;
                case self::OPER_MULT:
                    $valuesArr[$thingToCalculate] = $firstOperator * $secondOperator;
                    break;
                case self::OPER_SUB:
                    $valuesArr[$thingToCalculate] = $firstOperator - $secondOperator;
                    break;
                case self::OPER_ADD:
                    $valuesArr[$thingToCalculate] = $firstOperator + $secondOperator;
                    break;
                default:
                    \Log::error(sprintf("NO IDEA WHAT TO DO WITH THESE CALCULATION INSTRUCTIONS : "),
                        $instructionArray);
            }
        }
        return $valuesArr;
    }

    /**
     * Generate and calculate values for a totals array
     * @param Array|Collection $stuff An array of data from getDisplayDataForDates(), or a collection of eloquent objects that extend this class
     * @return array                        An array of values to display at the foot of the report, denoting the totals.
     */
    public static function generateTotals($stuff, $type = null)
    {
        //if a type is set, only total the rows that match that type
        if ($type != null && $type != '') {
            foreach ($stuff as $key => $thing) {
                if (isset($thing['post_settings_id'])) {
                    $setting = PostSetting::find($thing['post_settings_id']);
                    if ($setting && $setting->type != $type) {
                        unset($stuff[$key]);
                    }
                }
            }
        }
        //create simple totalsArray to populate with calculated values.
        $totalsArray = [];
        //add values in all $adds
        foreach (static::$adds as $addKey) {
            foreach ($stuff as $thing) {
                //save each to totalsArray under their column name as key
                $totalsArray[$addKey] += $thing[$addKey];
            }
        }
        $totalsArray = self::addCalculatedValues($totalsArray);
        //create offsets key in totalsArray equal to the size of the $offsets array
        $totalsArray['offsets'] = count(static::$offsets);
        //return totalsArray
        return $totalsArray;
    }

    public static function createAndUpdate($attributes)
    {
        if (!isset($attributes['created_at'])) {
            return null;
        }
        //attempt to find an existing row with this date
        $row = self::where('created_at', $attributes['created_at']);
        //go through offsets array, make sure they are given in the attributes array, and add the clause to the $row builder instance
        foreach (static::$offsets as $offsetKey) {
            if (array_key_exists($offsetKey, $attributes)) {
                $row->where($offsetKey, $attributes[$offsetKey]);
            }
        }
        //execute the builder and return either a single object or null
        $row = $row->first();
        if ($row != null) {
            //if found, update
            $row->update($attributes);
        } else {
            //if not found, create new
            $row = self::create($attributes);
        }
        return $row;
    }

    public static function getChildren()
    {
        $path = app_path() . '/Models';
        $lPrefix = 'App\Models\\';
        $out = [];
        $results = scandir($path);
        foreach ($results as $result) {
            if ($result === '.' or $result === '..') {
                continue;
            }
            $filename = $path . '/' . $result;
            if (is_dir($filename)) {
                $out = array_merge($out, self::getChildren($filename));
            } else {
                $out[] = $lPrefix . substr($result, 0, -4);
            }
        }
        $children = array();
        foreach ($out as $class) {
            if (is_subclass_of($class, 'App\Models\ReportRollupBase')) {
                $children[] = $class;
            }
        }
        return $children;
    }

    public function scopeDateRange($query, $startDate = null, $endDate = null)
    {

        if ($startDate == 'today') {
            $startDate = date('Y-m-d');
        }

        if ($startDate) {
            $startTs = @strtotime($startDate);
            if (!$startTs) {
                throw new \Exception("The given start date is not value");
            }

            if ($endDate) {
                $endTs = @strtotime($endDate);
                if (!$endTs) {
                    throw new \Exception("The given end date is not value");
                }

                if ($endTs < $startTs) {
                    throw new \Exception("End date should be after start date");
                }
            }

            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [
                    date('Y-m-d 00:00:00', strtotime($startDate)),
                    date('Y-m-d 23:59:59', strtotime($endDate)),
                ]);
            } else {
                $query->where('created_at', '>=', date('Y-m-d 00:00:00', strtotime($startDate)));
            }
        }

        $this->startDate = "$startDate 00:00:00";
        $this->endDate = "$endDate 23:59:59";

        return $query;
    }

    public static function getCurrentAttributes($attributes)
    {
        if (!isset($attributes['created_at']) || !$attributes['created_at']) {
            return [];
        }
        $builder = self::where('created_at', $attributes['created_at']);
        foreach (static::$offsets as $key) {
            if (array_key_exists($key, $attributes)) {
                $builder->where($key, $attributes[$key]);
            }
        }
        $thing = $builder->first();
        if (!$thing) {
            return [];
        }
        return $thing->attributesToArray();
    }

    public static function ecpmHasChangedBy($newAttrs, $oldAttrs, $percent = 50)
    {
        if (!array_key_exists('ecpm', static::$calcs)) {
            return false;
        }
        $yesterdata = self::getDisplayDataForDates('yesterday', 'yesterday', $newAttrs);
        $yesterdata = self::addCalculatedValues($yesterdata);
        foreach (static::$calcs as $calc) {
            unset($oldAttrs[$calc]);
            unset($newAttrs[$calc]);
        }
        foreach (static::$adds as $key) {
            $oldAttrs[$key] += $yesterdata[0][$key];
            $newAttrs[$key] += $yesterdata[0][$key];
        }
        $oldAttrs = self::addCalculatedValues($oldAttrs);
        $newAttrs = self::addCalculatedValues($newAttrs);
        if ($oldAttrs['ecpm'] == 0 || $newAttrs['ecpm'] == 0) {
            //avoid divide by zero nonsense
            return false;
        }
        $var = (abs(1 - ($oldAttrs['ecpm'] / $newAttrs['ecpm'])) * 100);
        return $var > $percent;
    }

    public static function sendNotification($about, $attrs, $oldAttrs)
    {

        $userAlerts = UserAlert::where('level', $about)->get();
        foreach ($userAlerts as $userAlert) {
            $user = User::find($userAlert->user_id);
            if (!$user) {
                break;
            }
            $emailData = [
                "new" => $attrs,
                "old" => $oldAttrs,
                "about" => $about
            ];
            if ($userAlert->alert_email) {
                //send email to $user->email
                Mail::send("email.ecpm_warning", $emailData, function ($message) use ($userAlert, $user) {
                    $message->to($user->email);
                    $message->from('admin@simplysweeps.com', 'Alert System');
                    $message->subject("ECPM ALERT");
                });
            }
            if ($userAlert->alert_slack) {
                //send slack msg based on $user->email (??)
            }
            if ($userAlert->alert_text) {
                //send text based on $user->phone (many of which are currently null)
            }
        }
    }

    public static function generateCsvOfReport($startDate, $endDate, $where = [], $type = null)
    {
        $displayData = self::getDisplayDataForDates($startDate, $endDate, $where);
        $totalsRow = self::generateTotals($displayData, $type);

        $csvHeaders = array_merge(static::$offsets, static::$adds, array_keys(static::$calcs));
        $filename = substr(static::class,
                23) . Carbon::parse($startDate)->toDateString() . '_to_' . Carbon::parse($endDate)->toDateString() . '.csv';

        $file = storage_path(sprintf('/reports/%s', $filename));

        $fh = @fopen($file, 'w');
        if (!$fh) {
            \Log::error(sprintf('Could not open file "%s" for writing', $file));
            exit;
        }
        fputcsv($fh, $csvHeaders);

        foreach ($displayData as $row) {
            $out = [];
            foreach ($csvHeaders as $key) {
                if (isset($row[$key]) && $row[$key] !== null) {
                    switch ($key) {
                        case 'advertiser_id':
                            $advertiser = Advertiser::find($row[$key]);
                            if ($advertiser) {
                                $out[] = 'ID: ' . $row[$key] . ' : ' . $advertiser->name;
                            } else {
                                $out[] = $row[$key];
                            }
                            break;
                        case 'ad_id':
                            $ad = Ad::find($row[$key]);
                            if ($ad) {
                                $out[] = 'ID: ' . $row[$key] . ' : ' . $ad->name;
                            } else {
                                $out[] = $row[$key];
                            }
                            break;
                        case 'post_id':
                            $post = Post::find($row[$key]);
                            if ($post) {
                                $out[] = 'ID: ' . $row[$key] . ' : ' . $post->name;
                            } else {
                                $out[] = $row[$key];
                            }
                            break;
                        default:
                            $out[] = $row[$key];
                    }
                } else {
                    $out[] = '-';
                }
            }
            fputcsv($fh, $out);
        }
        $totals = [];
        foreach ($csvHeaders as $key) {
            if (isset($totalsRow[$key]) && $totalsRow[$key] !== null) {
                $totals[] = $totalsRow[$key];
            } else {
                $totals[] = 'Totals';
            }
        }
        fputcsv($fh, $totals);
        @fclose($fh);
        return action('Admin\DownloadController@index', ['reports', $filename]);
    }
}
