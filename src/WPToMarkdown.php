<?php
namespace TJM;
use DateTime;
use League\HTMLToMarkdown\HtmlConverter;
use PDO;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TJM\DB;
use TJM\TaskRunner\Task;
use TJM\WikiSite\FormatConverter\ConverterInterface;
use TJM\WikiSite\FormatConverter\MarkdownToCleanMarkdownConverter;
use TJM\WPToMarkdown\Event\ConvertedContentEvent;

class WPToMarkdown extends Task{
	protected $batch = 250; //--how many posts to query for at once.  Larger number risks hitting memory ceiling but goes faster
	protected $db; //--DB instance, DSN string, or array of arguments for DB
	protected $dbPrefix = ''; //--prefix to db tables
	protected $destination; //--path to save files to
	protected ?EventDispatcherInterface $eventDispatcher = null;
	protected $origDestination; //--path to save original content as files to.  Primarily to verify changes locally.  No-op if empty
	protected $permalinkStructure = '/%year%/%monthnum%/%day%/%postname%/'; //--match WordPress's permalink structure setting. -! not fully implemented
	protected $toMarkdownConverter; //--instance of ConverterInterface or League\…\HtmlToMarkdownConverterInterface to convert to markdown.  will create one if none provided.

	public function __construct($opts = []){
		foreach($opts as $key=> $value){
			$this->$key = $value;
		}
	}

	public function __invoke(){
		return $this->do();
	}
	public function do(){
		if(!($this->db instanceof DB)){
			$this->db = new DB($this->db);
		}
		if(empty($this->toMarkdownConverter)){
			$this->toMarkdownConverter = new MarkdownToCleanMarkdownConverter(new HtmlConverter([
				'hard_break'=> true,
				'preserve_comments'=> true,
			]), false);
		}

		//--must disable `ONLY_FULL_GROUP_BY` mode to allow semi-ambiguous tags query to be run along with image meta query
		$this->db->query('SET sql_mode=(SELECT REPLACE(@@sql_mode,"ONLY_FULL_GROUP_BY",""))')->execute([]);

		//--grab categories so we can separate them from tags later (more efficient to do in single query)
		$cats = [];
		$catQuery = $this->db->query([
			'values'=> 'this.slug',
			'table'=> $this->dbPrefix . 'terms',
			'joins'=> [
				'tt'=> [
					'on'=> 'tt.term_id = this.term_id',
					'table'=> $this->dbPrefix . 'term_taxonomy',
				],
			],
			'where'=> [
				'tt.taxonomy'=> 'category',
				'this.slug IS NOT NULL',
			],
		]);
		while(($cat = $catQuery->fetch())){
			$cats[] = $cat['slug'];
		}

		//--build general post query
		$getQueryParts = [
			'table'=> $this->dbPrefix . 'posts',
			'where'=> [
				'this.post_type'=> 'post',
				'this.post_status'=> 'publish',
				// 'this.ID'=> 567,
			],
		];

		//--grab post count so we know how many to loop through
		$count = $this->db->query(array_merge($getQueryParts, [
			'values'=> 'count(this.ID) as cnt',
		]))->fetch()['cnt'];

		//--build query for actual post data
		$getQuery = $this->db->prepare(array_merge($getQueryParts, [
			'values'=> 'this.*'
				. ', ifi.meta_value as image, ialt.meta_value AS image_alt'
				. ', GROUP_CONCAT(t.slug SEPARATOR ",") as tags'
			,
			'joins'=> [
				'pmi'=> [
					'on'=> 'this.ID = pmi.post_id AND pmi.meta_key = "_thumbnail_id"',
					'table'=> $this->dbPrefix . 'postmeta',
					'type'=> 'LEFT',
				],
				'i'=> [
					'on'=> 'i.ID = pmi.meta_value',
					'table'=> $this->dbPrefix . 'posts',
					'type'=> 'LEFT',
				],
				'ifi'=> [
					'on'=> 'ifi.post_id = i.ID AND ifi.meta_key = "_wp_attached_file"',
					'table'=> $this->dbPrefix . 'postmeta',
					'type'=> 'LEFT',
				],
				'ialt'=> [
					'on'=> 'ialt.post_id = i.ID AND ialt.meta_key = "_wp_attachment_image_alt"',
					'table'=> $this->dbPrefix . 'postmeta',
					'type'=> 'LEFT',
				],
				'tr'=> [
					'on'=> 'tr.object_id = this.ID',
					'table'=> $this->dbPrefix . 'term_relationships',
					'type'=> 'LEFT',
				],
				'tt'=> [
					'on'=> 'tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN("category","post_tag")',
					'table'=> $this->dbPrefix . 'term_taxonomy',
					'type'=> 'LEFT',
				],
				't'=> [
					'on'=> 't.term_id = tt.term_id',
					'table'=> $this->dbPrefix . 'terms',
					'type'=> 'LEFT',
				],
			],
			'limit'=> $this->batch,
			'offset'=> ':offset',
			'groupBy'=> 'this.ID'
		]));
		$offset = 0;
		$realCount = 0;
		$modifiedCount = 0;
		do{
			$getQuery->getQuery()->setParameter('offset', $offset);
			$posts = $this->db->query($getQuery);
			while(($post = $posts->fetch())){
				++$realCount;
				$path = $this->getPostPath($post);

				//--build meta
				$meta = ['categories'=> []];
				foreach([
					'comment_count'=> 'comment_count',
					'date'=> 'post_date',
					'date_gmt'=> 'post_date_gmt',
					'excerpt'=> 'post_excerpt',
					'guid'=> 'guid',
					'id'=> 'ID',
					'image'=> 'image',
					'image_alt'=> 'image_alt',
					'modified'=> 'post_modified',
					'modified_gmt'=> 'post_modified_gmt',
					'name'=> 'post_name',
					'pings'=> 'pinged',
					'tags'=> 'tags',
				] as $key=> $from){
					if(!isset($post[$from])){
						continue;
					}
					$value = trim($post[$from]);
					switch($key){
						case 'pings':
							$value = trim(str_replace("\r\n", "\n", $value));
							if($value){
								$value = explode("\n", $value);
							}else{
								continue 2;
							}
						break;
						case 'date':
						case 'modified':
							$diff = date_diff(new DateTime($value), new DateTime($post['post_' . $key . '_gmt']));
							$diff = ($diff->invert ? '+' : '-') . str_pad($diff->h, 2, '0', STR_PAD_LEFT) . ':00';
							$value = new DateTime($value . $diff);
						break;
						case 'date_gmt':
						case 'modified_gmt':
							$value = new DateTime($value);
						break;
						case 'id':
						case 'comment_count':
							$value = (int) $value;
							if($value === 0){
								continue 2;
							}
						break;
						case 'tags':
							$value = explode(',', $value);
							//--sort alpha
							sort($value);
							//--pull out categories
							foreach($value as $subkey=> $tag){
								if(in_array($tag, $cats)){
									$meta['categories'][] = $tag;
									unset($value[$subkey]);
								}
							}
							//--reindex tags so they are treated as normal array
							if(count($meta['categories']) && count($value)){
								$value = array_values($value);
							}
						break;
					}
					if(
						!(is_string($value) && $value === '')
						&& !(is_array($value) && empty($value))
					){
						$meta[$key] = $value;
					}
				}

				//--build main content
				$content = $post['post_content_filtered'] ?: $post['post_content'];

				//-- output original content, if set
				if(!empty($this->origDestination)){
					$origDestination = str_replace($this->destination, $this->origDestination, $path);
					if(!file_exists($origDestination) || $content !== file_get_contents($origDestination)){
						$dir = dirname($origDestination);
						if(!is_dir($dir)){
							echo "- making dir {$dir}\n";
							exec('mkdir -p ' . escapeshellarg($dir));
						}
						echo "- writing {$origDestination}\n";
						file_put_contents($origDestination, $content);
					}
				}


				//--fix: some posts seem to have wrong line break
				$content = str_replace("\r\n", "\n", $content);

				//--fix: posts seem to have some chars encoded, shouldn't when markdown
				if(strpos($content, '<pre>') === false){
					$content = htmlspecialchars_decode($content);
				}

				//--convert to markdown
				try{
					$content = $this->toMarkdownConverter->convert($content);
				}catch(\Exception $e){
					if(function_exists('dump')){
						dump($post);
						dump($e);
					}
					echo "***Error converting post {$post['ID']}***\n";
					die();
					$content = '500 error converting post';
				}

				//--add h1
				if($post['post_title']){
					$content = $post['post_title'] . "\n" . str_repeat('=', strlen($post['post_title'])) . "\n\n" . $content;
				}

				//--event dispatcher
				if($this->eventDispatcher){
					$event = new ConvertedContentEvent($content, $path);
					$this->eventDispatcher->dispatch($event);
					$content = $event->getContent();
				}

				//--write full content if not matching existing file
				$fullContent = "---\n" . Yaml::dump($meta, 1, 1) . "---\n\n" . $content;
				if(!file_exists($path) || $fullContent !== file_get_contents($path)){
					$dir = dirname($path);
					if(!is_dir($dir)){
						echo "- making dir {$dir}\n";
						exec('mkdir -p ' . escapeshellarg($dir));
					}
					echo "- writing {$path}\n";
					file_put_contents($path, $fullContent);
					++$modifiedCount;
				}
			}
			$offset += $this->batch;
		}while($offset < $count);
		echo "Wrote {$modifiedCount} of {$realCount} ({$count}) posts\n";
		return $modifiedCount;
	}

	protected function getPostPath(array $post){
		$path = $this->permalinkStructure;
		if(substr($path, 0, 1) !== '/'){
			$path = '/' . $path;
		}
		$date = new DateTime($post['post_date']);
		if(strpos($path, '%year%') !== false){
			$path = str_replace('%year%', $date->format('Y'), $path);
		}
		if(strpos($path, '%monthnum%') !== false){
			$path = str_replace('%monthnum%', $date->format('m'), $path);
		}
		if(strpos($path, '%day%') !== false){
			$path = str_replace('%day%', $date->format('d'), $path);
		}
		if(strpos($path, '%postname%') !== false){
			$path = str_replace('%postname%', $post['post_name'] ?: $post['ID'], $path);
		}
		if(substr($path, -1, 1) === '/'){
			$path = substr($path, 0, -1);
		}
		$path .= '.md';
		return $this->destination . $path;
	}
}
