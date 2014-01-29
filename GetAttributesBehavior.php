<?php
/**
 * Class GetAttributesBehavior
 * @author leo
 * @date 15.07.13
 *
 * Converts an AR model to an array, includes associated relations.
 **/
class GetAttributesBehavior extends CActiveRecordBehavior {

	public $defaultRESTscenario = 'rest';

	public function getRestAttributes($opts = array()){
		return $this->toArray($this->defaultRESTscenario, $opts);
	}

	/**
	 * Возвращает атрибуты модели вместе с ее связанными, моделями,
	 * (если) которые уже загружены с помощью жадной загрузки через with или scopes, или указаны в опциях 'with'=>array(...)
	 * Пример:
	 * $record->toArray('rest', ['with'=>['properties.propertyName', 'features'])
	 *
	 * @param string $scenario
	 * @param array $opts в опциях можно передать массив with как в CActiveRecord
	 * @return array
	 */
	public function toArray($scenario = '', $opts = array()){
		$owner = $this->getOwner();

		$opts['scenario'] = $scenario;
		if (empty($scenario)) $opts['scenario'] = $owner->getScenario();

		if(isset($opts['with']) && is_array($opts['with'])){
			$with = array();
			foreach($opts['with'] as $key=>$item){
				if(is_numeric($key)){
					if(strpos($item, '.') > 0) {
						$items = explode('.', $item);
						$with[$items[0]][$items[1]] = array();
						switch (count($items)){
							case 3:
								$with[$items[0]][$items[1]][$items[2]] = array();
								break;
							case 4:
								$with[$items[0]][$items[1]][$items[2]] = array($items[3]=>array());
						}
					} else {
						$with[$item] = array();
					}
				}
			}
			if(!empty($with))
				$opts['with'] = $with;
		}

		return $this->loop($owner, $opts);
	}

	/**
	 * Возвращает результат в JSON формате
	 * @param string $scenario
	 * @param array $opts
	 * @return string
	 */
	public function toJSON($scenario = '', $opts = array()){
		return CJSON::encode($this->toArray($scenario, $opts));
	}

	/**
	 * Возвращает только безопасные атрибуты строго для установленного сценария.
	 * Если сценарий не задан, то получает сценарий из модели,
	 * если он не установлен у модели, или сценарий не используется в правилах модели, то возвращает пустой массив;
	 * Если атрибута не существует, ему будет присвоен null (@see CActiveRecord::getAttribute())
	 * @param string $scenario use a scenario to only display safe attributes
	 * @return array
	 */
	public function getAttributesByScenario($scenario = '') {
		$owner = $this->getOwner();

		if (empty($scenario) || is_null($scenario))
			$scenario = $owner->getScenario();

		// check if user supplied a scenario, and if this object has any rules
		if (!empty($scenario) && is_array($owner->rules())) {

			$attributes = array();
			foreach ($owner->rules() as $rule) {
				$on=array();
				if(isset($rule['on'])) {
					if(is_array($rule['on'])) {
						$on=$rule['on'];
					} else {
						$on=preg_split('/[\s,]+/',$rule['on'],-1,PREG_SPLIT_NO_EMPTY);
					}
				}

				// check if this scenario name matches the supplied one
				// & check if this is the 'safe' option
				if (in_array($scenario, $on) && (isset($rule[0],$rule[1]) && $rule[1] === 'safe')) {
					if(is_array($rule[0])) {
						$safe_attributes = $rule[0];
					} else {
						$safe_attributes = preg_split('/[\s,]+/',$rule[0],-1,PREG_SPLIT_NO_EMPTY);
					}

					foreach ($safe_attributes as $safe_attribute) {
						if(!$owner->hasAttribute($safe_attribute)){
							$getter='get'.$safe_attribute;
							if(method_exists($owner, $getter))
								$attributes[$safe_attribute] = $owner->$getter();
						}
						if(!isset($attributes[$safe_attribute])) {
							$attributes[$safe_attribute] = $owner->getAttribute($safe_attribute);
						}
					}
				}
			}
			return $attributes;

		}
		return array();
		//return $owner->attributes;
	}

	/**
	 * @param CActiveRecord $obj
	 * @param array $opts
	 * @return array
	 */
	public function loop($obj, $opts) {
		// assign the attributes of current rel to array
		$array = $obj->getAttributesByScenario((isset($opts['scenario']) ? $opts['scenario'] : ''));

		// loop relations
		foreach (array_keys($obj->getMetadata()->relations) as $rel_id) {
			// если релейшен загружен (через scopes или 'with') или указан опциях ($opts['with']),
			// тогда он будет получен и записан в результат
			if ($obj->hasRelated($rel_id) || isset($opts['with'], $opts['with'][$rel_id])) {
				$next_opts = $opts;
				if(isset($opts['with'], $opts['with'][$rel_id]))
					$next_opts['with'] = $opts['with'][$rel_id];

				$relation = $obj->getRelated($rel_id);

				if (is_null($relation) || is_scalar($relation)){
					$array[$rel_id] =  $relation;
				} else if (is_object($relation)) {
					$array[$rel_id] = $this->loop($relation, $next_opts);
				} else if (is_array($relation)) {
					$array[$rel_id] = array_map(function($rel) use($next_opts) {
						return $rel->loop($rel, $next_opts);
					}, $relation);
				}
			}
		}

		return $array;
	}
}