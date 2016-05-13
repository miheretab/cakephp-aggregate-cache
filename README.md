# cakephp-aggregate-cache
=======================

A Behavior plugin for CakePHP that extends the idea of counterCache and counterScope to more fields.

Note: this is made for Cake 3.x upgraded from git extension of AggregateCache behavior by CWB IT.

## Installation
====

_[Using [Composer](http://getcomposer.org/)]_

Add the plugin to your project's `composer.json` - something like this:

	{
		"require": {
			"miheretab/cakephp-aggregate-cache": "3.x"
		}
	}

### Enable plugin

In 2.0 you need to enable the plugin your `config/bootstrap.php` file:
```
    CakePlugin::load('AggregateCache');
```
If you are already using `CakePlugin::loadAll();`, then this is not necessary.

_[Use as Model]_

Or you can simply put it under src/Model/Behavior and change the namespace in AggregateCacheBehavior

```
namespace App\Model\Behavior;
```

## Usage


AggregateCache behavior caches the result of aggregate calculations (min, max, avg, sum) in tables that are joined by a hasMany / belongsTo association. I usually think of aggregates as being easy to calculate when needed, though in situations where the aggregate value is needed more often than the underlying data changes it makes sense to cache the calculated value. Caching the result of the aggregate calculation also makes it easier to write queries that filter or sort on the aggregate value. This behavior makes caching the result of aggregate calculations easy. AggregateCache is based on the CounterCache behavior ([url]http://bakery.cakephp.org/articles/view/countercache-or-counter_cache-behavior[/url]).
To introduce the AggregateCache behavior let's use a posts and comments example. The date of the most recent comment, and the maximum and average ratings from each comment will be cached to the Post model, which will make it easy to use this information for display or as filters in other queries.


#### Posts table:
```mysql
CREATE TABLE `posts` ( 
  `id` int(10) unsigned NOT NULL auto_increment, 
  `created` datetime default NULL, 
  `modified` datetime default NULL, 
  `name` varchar(100) NOT NULL, 
  `description` mediumtext, 
  `average_rating` float default NULL, 
  `best_rating` float default NULL, 
  `latest_comment_date` datetime default NULL, 
  PRIMARY KEY  (`id`) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8; 
```
#### Comments table:
```mysql
CREATE TABLE `comments` ( 
  `id` int(10) unsigned NOT NULL auto_increment, 
  `created` datetime default NULL, 
  `modified` datetime default NULL, 
  `name` varchar(100) NOT NULL, 
  `description` mediumtext, 
  `post_id` int(10) unsigned NOT NULL, 
  `rating` int(11) default NULL, 
  `visible` tinyint(1) unsigned NOT NULL default â€˜1â€™, 
  PRIMARY KEY  (`id`), 
  KEY `comments_ibfk_1` (`post_id`), 
  CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8; 
```

#### Comments table:
```php
<?php  
class CommentsTable extends Table
{

	//...
    public function initialize(array $config)
    {
		...
        $this->belongsTo('Posts', [
            'foreignKey' => 'post_id'
        ]);
		
		//use 'AggregateCache.AggregateCache' if it is as a Plugin or 'AggregateCache' if it is as Model Behavior
		$this->addBehavior('AggregateCache.AggregateCache', [
				'created' => [      #Syntax OPT1 - 'created' is the name of the name of the field we want to trigger by
					 'model'=>'Posts',   # Post is the model we want to update with the new details
					 'max'=>'latest_comment_date' # 'Post.latest_comment_date' is the field we'll update with the 'max' function (based on 'Comment.created' as indicated above)
				],
				[
					 'field'=>'rating', #Syntax OPT2 - this is more explicit and easy to read
					 'model'=>'Posts',   #The Model which holds the cache keys
					 'avg'=>'average_rating', # Post.average_rating will be set to the 'avg' of 'Comment.rating'
					 'max'=>'best_rating',    # Post.best_rating will be set to the 'max' of 'Comment.rating'   
					 'conditions'=>array('visible'=>'1'), # only look at Comments where Comment.visible = 1
					 'recursive'=>-1    # don't need related model info
			   ], 
		]);		
    }
	//...
?>
```

The AggregateCache behavior requires a config array that specifies, at minimum, the field and aggregate function to use in the aggregate query, and the model and field to store the cached value. The example above shows the minimal syntax in the first instance (which specifies the aggregate field as a key to the config array), and the normal syntax in the second instance. The second instance also uses the optional parameters for conditions and recursive, and specifies more than one aggregate to be calculated and stored.


To show this more clearly, the config array can specify:
```
 $this->addBehavior('AggregateCache', [ 
   'field'=>'name of the field to aggregate', 
   'model'=>'belongsTo model alias to store the cached values', 
   'min'=>'field name to store the minimum value', 
   'max'=>'field name to store the maximum value', 
   'sum'=>'field name to store the sum value', 
   'avg'=>'field name to store the average value' 
   'count' => 'field name to store the count value', 
   'conditions'=>array(), // conditions to use in the aggregate query 
   'recursive'=>-1 // recursive setting to use in the aggregate query 
]); 
```
Field and model must be specified, and at least one of min, max, sum, or avg must be specified.


The model name must be one of the keys in the belongsTo array (so if an alias is used in belongsTo, the same alias must be used in the AggregateCache config).


Specifying conditions for the aggregate query can be useful, for example, to calculate an aggregate using only the comments that have been approved for display on the site. If the conditions parameter is not provided, the conditions defined in the belongsTo association are used. (Conditions can be an empty array to specify that no conditions be used in the aggregate query.) Note: If you need to specify different conditions for different aggregates of the same field, you will need to specify 'field' explicitly and not as a key to the config array.


Specifying recursive is optional, though if your conditions donâ€™t involve a related table recursive should be set to -1 to avoid having unnecessary joins in the aggregate query.


Note: If you restrict saves to specific fields by specifying a fieldList you will need to include the foreignKey fields used to associate the model that will hold cached values, otherwise the behavior will not have the id's available to query.
