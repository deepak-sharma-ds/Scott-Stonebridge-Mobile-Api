<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Configuration extends Model
{
	use HasFactory;

	protected $table = 'configurations';
	public $timestamps = false;
	protected $fillable = [
		'name',
		'value',
		'title',
		'input_type',
		'description',
		'params',
		'editable',
		'order',
		'weight'
	];

	public function init(){

		$config_arr = array();
		$settings = Configuration::pluck('value', 'name')->toArray();
		foreach ($settings as $name => $value)
		{
			\Config::set($name, $value);
		}
	}

	public function getprefix(){
		
		$allprefix = array();
		$allprefixarray = Configuration::pluck('name', 'id');
		foreach($allprefixarray as $key => $value){
			 $keyE = explode('.', $value);
			 if (!in_array($keyE['0'], $allprefix)){
				$allprefix[] = str_replace('_',' ',$keyE['0']);
			 }
		}

		return $allprefix;

	}

	/*
	* Save a configuration
	*/
	public function saveConfig($key, $value){
		$result = Configuration::updateOrCreate(['name' => $key], ['value' => $value]);
	}

	public function moveUp($id, $step) {

		$currentPosition = Configuration::select('id', 'order')->findorFail($id);
		
		if($currentPosition->id > 1)
		{
			$limit = $step;

			$changePosition = Configuration::select('id', 'order')
								->where('order', '<', $currentPosition->order)
								->orderBy('order', 'DESC')
								->limit($limit)
								->get()->toArray();

			
			$lastArray = end($changePosition);

			if(!empty($changePosition)){

			//dd($currentPosition, $changePosition, $lastArray);
				$currentPositionRes = Configuration::where('id', '=', $currentPosition->id)
								->update(['order'=> $lastArray['order']]);

				$changePositionId = ($limit > 1) ? $lastArray['id'] : $lastArray['id'];

				$changePositionRes = Configuration::where('id', '=', $changePositionId)
								->update(['order'=>$currentPosition->order]);
								
				return true;
			}
		}
		
		return  false;
		
	}

	public function moveDown($id, $step) {
		$currentPosition = Configuration::select('id', 'order')->findorFail($id);
		$maxOrder = Configuration::max('order');
		
		if($currentPosition->order < $maxOrder)
		{
			$limit = $step;

			$changePosition = Configuration::select('id', 'order')
								->where('order', '>', $currentPosition->order)
								->orderBy('order', 'ASC')
								->limit($limit)
								->get()->toArray();

			$lastArray = end($changePosition);
			$currentPositionRes = Configuration::where('id', '=', $currentPosition->id)
								->update(['order'=> $lastArray['order']]);

			$changePositionId = ($limit > 1) ? $lastArray['id'] : $lastArray['id'];

			$changePositionRes = Configuration::where('id', '=', $changePositionId)
								->update(['order'=>$currentPosition->order]);
			
			return true;
		}
		else
		{
			return  false;
		}
	}

	public static function getConfig($key)
	{
		$config = Configuration::pluck('value', 'name');
		return isset($config[$key]) ? $config[$key] : '';
	}

    public function getCreatedAtAttribute( $value ) {
        $dateFormat = config('Site.custom_date_format').' '.config('Site.custom_time_format');
        return (new Carbon($value))->format($dateFormat);
    }

    public function setCreatedAtAttribute( $value ) {
        $this->attributes['created_at'] = (new Carbon($value))->format('Y-m-d H:i:s');
    }
}
