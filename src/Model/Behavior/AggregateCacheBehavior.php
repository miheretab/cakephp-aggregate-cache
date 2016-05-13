<?php 
/** 
 * AggregateCache Behavior 
 * 
 * Usage: 
 * $this->addBehavior('AggregateCache', [ 
 *   'field'=>'name of the field to aggregate', 
 *   'model'=>'belongsTo model alias to store the cached values', 
 *   'min'=>'field name to store the minimum value', 
 *   'max'=>'field name to store the maximum value', 
 *   'sum'=>'field name to store the sum value', 
 *   'avg'=>'field name to store the average value' 
 *   'count' => 'field name to store the count value', 
 *   'conditions'=>array(), // conditions to use in the aggregate query 
 *   'recursive'=>-1 // recursive setting to use in the aggregate query 
 *  ]); 
 * 
 * Example: 
 * class CommentsTable extends Table { 
 *	 ...
 *   public function initialize(array $config)
 *   {
 *		...
 *       $this->belongsTo('Posts', [
 *           'foreignKey' => 'post_id'
 *       ]);
 *		
 *		$this->addBehavior('AggregateCache', [
 *				'created' => [      #Syntax OPT1 - 'created' is the name of the name of the field we want to trigger by
 *					 'model'=>'Posts',   # Post is the model we want to update with the new details
 *					 'max'=>'latest_comment_date' # 'Post.latest_comment_date' is the field we'll update with the 'max' function (based on 'Comment.created' as indicated above)
 *				],
 *				[
 *					 'field'=>'rating', #Syntax OPT2 - this is more explicit and easy to read
 *					 'model'=>'Posts',   #The Model which holds the cache keys
 *					 'avg'=>'average_rating', # Post.average_rating will be set to the 'avg' of 'Comment.rating'
 *					 'max'=>'best_rating',    # Post.best_rating will be set to the 'max' of 'Comment.rating'   
 *					 'conditions'=>array('visible'=>'1'), # only look at Comments where Comment.visible = 1
 *					 'recursive'=>-1    # don't need related model info
 *			   ], 
 *		]);	
 *   }
 * } 
 * 
 * Each element of the configuration array should be an array that specifies: 
 * A field on which the aggregate values should be calculated. The field name may instead be given as a key in the configuration array.
 * A model that will store the cached aggregates. The model name must match the alias used for the model in the belongsTo array.
 * At least one aggregate function to calculate and the field in the related model that will store the calculated value.
 *    Aggregates available are: min, max, avg, sum, count 
 * A conditions array may be provided to filter the query used to calculate aggregates. 
 *    If not specified, the conditions of the belongsTo association will be used. 
 * A recursive value may be specified for the aggregate query. If not specified Cake's default will be used. 
 *    If it's not necessary to use conditions involving a related table, setting recursive to -1 will make the aggregate query more efficient.
 * 
 * @author Miheretab (original author CWB IT)
 */ 
 
namespace AggregateCache\Model\Behavior;

use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;

class AggregateCacheBehavior extends Behavior {

    public $foreignTableIDs = array(); 
	public $belongsTo = array();
    public $functions = array('min', 'max', 'avg', 'sum', 'count'); 	
	protected $_defaultConfig = array();

    public function initialize(array $config) {
        foreach ($config as $k => $aggregate) { 
            if (empty($aggregate['field'])) { 
                $aggregate['field'] = $k; 
            } 
            if (!empty($aggregate['field']) && !empty($aggregate['model'])) { 
                $this->_defaultConfig[$this->_table->alias()][] = $aggregate; 
            } 
        } 
		
		foreach($this->_table->associations()->type('BelongsTo') as $assocData) {
			$this->belongsTo[$assocData->name()] = ['foreignKey' => $assocData->foreignKey(), 'conditions' => $assocData->conditions()];
		}		
    } 

    private function __updateCache(array $aggregate, $foreignKey, $foreignId) { 
        $assocModel = & $this->_table->{$aggregate['model']}; 
        $functions = array();
        foreach ($aggregate as $function => $cacheField) {
            if (!in_array($function, $this->functions)) { 
                continue; 
            } 
			$functions[] = $function;
        } 
        if (count($functions) > 0) { 
            $conditions = array($this->_table->alias() . '.' . $foreignKey => $foreignId); 
            if (array_key_exists('conditions', $aggregate)) { 
                $conditions = array_merge($conditions, $aggregate['conditions']); 
            } else { 
                $conditions = array_merge($conditions, $this->belongsTo[$aggregate['model']]['conditions']); 
            }
            $recursive = (array_key_exists('recursive', $aggregate)) ? $aggregate['recursive'] : null; 
            $query = $this->_table->find('all', array( 
                        'conditions' => $conditions, 
                        'recursive' => $recursive, 
                        'group' => $this->_table->alias() . '.' . $foreignKey, 
                    )); 
			$results = [];
			foreach($functions as $function) {
				$result = $query->select([$function . '_value' => $query->func()->{$function}($aggregate['field'])])->first();
				if(isset($result)) {
					$results = $result->toArray();
				}
			}

            $newValues = array(); 
            foreach ($aggregate as $function => $cacheField) { 
                if (!in_array($function, $this->functions)) { 
                    continue; 
                }
                if (empty($results)) {
                    $newValues[$cacheField] = 0;
                } else {
                    $newValues[$cacheField] = $results[$function . '_value'];
                }
            } 
            if ($assocModel->exists(['id' => $foreignId])) {
				$assoc = $assocModel->get($foreignId);
				$assoc = $assocModel->patchEntity($assoc, $newValues);
				$assocModel->save($assoc);
            }
        } 
    }
    
	public function beforeSave(Event $event, EntityInterface $entity) {
		# Get the current foreignId in case it is different afterSave
		foreach($this->_table->associations()->type('BelongsTo') as $assocData) {
			$this->foreignTableIDs[$assocData->name()] = $entity->getOriginal($assocData->foreignKey());
		}
        return true;
    }    

    public function afterSave(Event $event, Entity $entity) {
        foreach ($this->_defaultConfig[$this->_table->alias()] as $aggregate) { 
			if (!array_key_exists($aggregate['model'], $this->belongsTo)) { 
                continue; 
            }
            $foreignKey = $this->belongsTo[$aggregate['model']]['foreignKey'];
            $foreignId = $entity->get($foreignKey);
            $this->__updateCache($aggregate, $foreignKey, $foreignId); 
            $oldForeignId = $this->foreignTableIDs[$aggregate['model']];
            if( !$entity->isNew() && $foreignId != $oldForeignId ) {
                $this->__updateCache($aggregate, $foreignKey, $oldForeignId);
            }
        }
    } 

    public function beforeDelete(Event $event, EntityInterface $entity) { 
        foreach($this->_table->associations()->type('BelongsTo') as $assocData) {
            $this->foreignTableIDs[$assocData->name()] = $entity->get($assocData->foreignKey()); 
        }
        return true; 
    } 

    public function afterDelete(Event $event, EntityInterface $entity) { 
        foreach ($this->_defaultConfig[$this->_table->alias()] as $aggregate) { 
            if (!array_key_exists($aggregate['model'], $this->belongsTo)) { 
                continue; 
            } 
            $foreignKey = $this->belongsTo[$aggregate['model']]['foreignKey']; 
            $foreignId = $this->foreignTableIDs[$aggregate['model']]; 
            $this->__updateCache($aggregate, $foreignKey, $foreignId); 
        } 
    }

} 
?>
