<?php

use Airtime\CcSubjsPeer;
use Airtime\MediaItem\WebstreamPeer;
use Airtime\MediaItem\PlaylistPeer;
use Airtime\MediaItem\AudioFilePeer;

use Airtime\MediaItem\AudioFileQuery;
use Airtime\MediaItem\WebstreamQuery;
use Airtime\MediaItem\PlaylistQuery;
use Airtime\MediaItemQuery;

abstract class Application_Service_DatatableService
{
	public function __construct() {
		
		$this->columns = $this->getColumns();
		$this->settings = $this->getSettings();
		$this->columnKeys = array_keys($this->columns);
	}
	
	//used for creating media search.
	//can't seem to call a class dynamically even though
	//http://www.php.net/manual/en/language.namespaces.importing.php
	//seems to say it's possible.
	protected $_ns = array(
		"AudioFilePeer" => "Airtime\MediaItem\AudioFilePeer",
		"PlaylistPeer" => "Airtime\MediaItem\PlaylistPeer",
		"WebstreamPeer" => "Airtime\MediaItem\WebstreamPeer",
		"CcSubjsPeer" => "Airtime\CcSubjsPeer",
	);
	
	protected function enhanceDatatablesColumns(&$datatablesColumns) {
	
		$checkbox = array(
			"sTitle" =>	"",
			"mDataProp" => "Checkbox",
			"bSortable" => false,
			"bSearchable" => false,
			"bVisible" => true,
			"sWidth" => "25px",
			"sClass" => "library_checkbox",
		);
	
		//add the checkbox to the beginning.
		array_unshift($datatablesColumns, $checkbox);
	}
	
	/*
	 * add display only columns such as checkboxs to the datatables response.
	* these should not be columns that could be calculated in the DB query.
	*/
	protected function enhanceDatatablesOutput(&$output) {
	
		//add in data for the display columns.
		foreach ($output as &$row) {
			$row["Checkbox"] = '<input type="checkbox">';
		}
	}
	
	
	public function makeDatatablesColumns() {
	
		$datatablesColumns = array();
	
		$columnOrder = $this->order;
		$columnInfo = $this->columns;
	
		for ($i = 0; $i < count($columnOrder); $i++) {
	
			$data = $columnInfo[$columnOrder[$i]];
	
			$datatablesColumns[] = array(
				"sTitle" =>	$data["title"],
				"mDataProp" => $columnOrder[$i],
				"bSortable" => isset($data["sortable"]) ? $data["sortable"] : true,
				"bSearchable" => isset($data["searchable"]) ? $data["searchable"] : true,
				"bVisible" => isset($data["visible"]) ? $data["visible"] : true,
				"sWidth" => $data["width"],
				"sClass" => $data["class"],
				"search" => isset($data["advancedSearch"]) ? $data["advancedSearch"] : null
			);
		}
	
		self::enhanceDatatablesColumns($datatablesColumns);
	
		return $datatablesColumns;
	}
	
	private function getColumnType($prop, $modelName) {
	
		if (strrpos($prop, ".") === false) {
			$base = $modelName;
			$column = $prop;
		}
		else {
			list($base, $column) = explode(".", $prop);
		}
	
		$b = explode("\\", $base);
		$b = array_pop($b);
		$class = $this->_ns["{$b}Peer"];
	
		$field = $class::translateFieldName($column, BasePeer::TYPE_PHPNAME, BasePeer::TYPE_FIELDNAME);
		
		$propelMap = $class::getTableMap();
		$col = $propelMap->getColumn($field);
		$type = $col->getType();
		
		return array(
			"type" => $type,
			"column" => "{$base}.{$column}"
		);
	}
	
	private function searchNumber($query, $col, $from, $to) {
		$num = 0;
	
		if (isset($from) && is_numeric($from)) {
			$name = "adv_{$col}_from";
			$cond = "{$col} >= ?";
			$query->condition($name, $cond, $from);
			$num++;
		}
	
		if (isset($to) && is_numeric($to)) {
			$name = "adv_{$col}_to";
			$cond = "{$col} <= ?";
			$query->condition($name, $cond, $to);
			$num++;
		}
	
		if ($num > 1) {
			$name = "adv_{$col}_from_to";
			$query->combine(array("adv_{$col}_from", "adv_{$col}_to"), 'and', $name);
		}
	
		//returns the final query condition to combine with other columns.
		return $name;
	}
	
	//need to return name of condition so that
	//all advanced search fields can be combined into an AND.
	private function searchString($query, $col, $value) {
	
		$name = "adv_{$col}";
		$cond = "{$col} iLIKE ?";
		$param = "%{$value}%";
		$query->condition($name, $cond, $param);
	
		return $name;
	}
	
	private function searchDate($query, $col, $from, $to) {
		$num = 0;
	
		if (isset($from) && preg_match_all('/(\d{4}-\d{2}-\d{2})/', $from)) {
			$name = "adv_{$col}_from";
			$cond = "{$col} >= ?";
	
			$date = Application_Common_DateHelper::UserTimezoneStringToUTCString($from);
			$query->condition($name, $cond, $date);
			$num++;
		}
	
		if (isset($to) && preg_match_all('/(\d{4}-\d{2}-\d{2})/', $to)) {
			$name = "adv_{$col}_to";
			$cond = "{$col} <= ?";
	
			$date = Application_Common_DateHelper::UserTimezoneStringToUTCString($to);
			$query->condition($name, $cond, $date);
			$num++;
		}
	
		if ($num > 1) {
			$name = "adv_{$col}_from_to";
			$query->combine(array("adv_{$col}_from", "adv_{$col}_to"), 'and', $name);
		}
	
		//returns the final query condition to combine with other columns.
		return $name;
	}
	
	protected function isVisible($prop, $settings) {
	
	}
	
	protected function buildQuery($query, $params) {
		//namespacing seems to cause a problem in the WHERE clause
		//if we don't prefix the PHP name with the model or alias.
		$modelName = $query->getModelName();
	
		$query->setFormatter('PropelOnDemandFormatter');
	
		$totalCount = $query->count();
	
		//add advanced search terms to query.
		$len = intval($params["iColumns"]);
		$advConds = array();
		for ($i = 0; $i < $len; $i++) {
	
			$prop = $params["mDataProp_{$i}"];
	
			if ($params["bSearchable_{$i}"] === "true"
				&& $params["sSearch_{$i}"] != ""
				&& in_array($prop, $this->columnKeys)
				&& !in_array($prop, $this->aliases)) {
	
				$info = self::getColumnType($prop, $modelName);
				$searchCol = $info["column"];
				$value = $params["sSearch_{$i}"];
				$separator = $params["sRangeSeparator"];
	
				switch($info["type"]) {
					case PropelColumnTypes::DATE:
					case PropelColumnTypes::TIMESTAMP:
						list($from, $to) = explode($separator, $value);
						$advConds[] = self::searchDate($query, $searchCol, $from, $to);
						break;
					case PropelColumnTypes::NUMERIC:
					case PropelColumnTypes::INTEGER:
						list($from, $to) = explode($separator, $value);
						$advConds[] = self::searchNumber($query, $searchCol, $from, $to);
						break;
					default:
						$advConds[] = self::searchString($query, $searchCol, $value);
						break;
				}
			}
		}
		if (count($advConds) > 0) {
			$query->where($advConds, 'and');
		}
	
		/*
			$search = $params["sSearch"];
		$searchTerms = $search == "" ? array() : explode(" ", $search);
		$andConditions = array();
		$orConditions = array();
	
		foreach ($searchTerms as $term) {
	
		$orConditions = array();
	
		$len = intval($params["iColumns"]);
		for ($i = 0; $i < $len; $i++) {
	
		if ($params["bSearchable_{$i}"] === "true"
				&& in_array($prop, $dataColumns)
				&& !in_array($prop, $aliasedColumns)) {
	
		$whereTerm = $params["mDataProp_{$i}"];
		if (strrpos($whereTerm, ".") === false) {
		$whereTerm = $modelName.".".$whereTerm;
		}
	
		$name = "{$term}{$i}";
		$cond = "{$whereTerm} iLIKE ?";
		$param = "{$term}%";
	
		$query->condition($name, $cond, $param);
			
		$info = self::getColumnType($prop, $modelName);
		$searchCol = $info["column"];
	
		$orConditions[] = $name;
		}
		}
	
		if (count($searchTerms) > 1) {
		$query->combine($orConditions, 'or', $term);
		$andConditions[] = $term;
		}
		else {
		$query->where($orConditions, 'or');
		}
		}
		if (count($andConditions) > 1) {
		$query->where($andConditions, 'and');
		}
		*/
	
		//ORDER BY statements
		$len = intval($params["iSortingCols"]);
		for ($i = 0; $i < $len; $i++) {
	
			$colNum = $params["iSortCol_{$i}"];
	
			if ($params["bSortable_{$colNum}"] == "true") {
				$colName = $params["mDataProp_{$colNum}"];
				$colDir = $params["sSortDir_{$i}"] === "asc" ? Criteria::ASC : Criteria::DESC;
	
				//need to lowercase the column name for the syntax generated by propel
				//to work properly in postgresql.
				if (in_array($colName, $this->aliases)) {
					$colName = strtolower($colName);
				}
	
				$query->orderBy($colName, $colDir);
			}
		}
	
		$filteredCount = $query->count();
	
		//LIMIT OFFSET statements
		$limit = intval($params["iDisplayLength"]);
		$offset = intval($params["iDisplayStart"]);
	
		$query
			->limit($limit)
			->offset($offset);
	
		$records = $query->find();
	
		return array (
			"totalCount" => $totalCount,
			"count" => $filteredCount,
			"media" => $records
		);
	}
	
	protected function makeArray(&$array, &$getters, $obj) {
	
		$key = array_shift($getters);
		$method = "get{$key}";
	
		if (count($getters) == 0) {
			$array[$key] = $obj->$method();
			return;
		}
	
		if (empty($array[$key])) {
			$array[$key] = array();
		}
		$a =& $array[$key];
		$nextObj = $obj->$method();
	
		return self::makeArray($a, $getters, $nextObj);
	}
	
	/*
	 * @param $coll PropelCollection formatted on demand.
	*
	* @return $output, an array of data with the columns needed for datatables.
	*/
	protected function createOutput($coll, $columns) {
	
		$output = array();
		foreach ($coll as $media) {
	
			$item = array();
			foreach ($columns as $column) {
	
				$getters = explode(".", $column);
				self::makeArray($item, $getters, $media);
			}
	
			$output[] = $item;
		}
	
		self::enhanceDatatablesOutput($output);
	
		return $output;
	}
	
	protected abstract function getColumns();
	protected abstract function getSettings();
}