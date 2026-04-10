<?php
namespace TJM;
use DateTime;
use Exception;
use League\HTMLToMarkdown\HtmlConverter;
use PDO;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TJM\DB;
use TJM\TaskRunner\Task;
use TJM\WikiSite\FormatConverter\ConverterInterface;
use TJM\WikiSite\FormatConverter\MarkdownToCleanMarkdownConverter;
use TJM\WPToMarkdown\Comment;
use TJM\WPToMarkdown\Event\ConvertedContentEvent;

class WPToMarkdown extends Task{
	//---how many posts to query for at once.  Larger number risks hitting memory ceiling but goes faster
	protected $batch = 250;
	//---DB instance, DSN string, or array of arguments for DB
	protected $db;
	//---prefix to db tables
	protected $dbPrefix = '';
	//---default category if none set
	protected $defaultCategory;
	protected ?EventDispatcherInterface $eventDispatcher = null;
	//---match WordPress's permalink structure setting. -! not fully implemented
	protected $permalinkStructure = '/%year%/%monthnum%/%day%/%postname%/';
	//---instance of ConverterInterface or League\…\HtmlToMarkdownConverterInterface to convert to markdown.  will create one if none provided.
	protected $toMarkdownConverter;
	//--paths
	protected $categoryPath = '/category';
	protected $commentsPath = '/comments';
	//---path to save files to
	protected $destination;
	protected $mentionsPath = '/mentions';
	//---path to save original content as files to.  Primarily to verify changes locally.  No-op if empty
	protected $origDestination;

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

		$modifiedCount = 0;

		//==cats
		//--grab categories so we can separate them from tags later (more efficient to do in single query)
		$cats = [];
		$catQuery = $this->db->query([
			'values'=> 'this.slug, this.name, tt.description',
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
		if($this->categoryPath){
			$catPath = $this->destination . $this->categoryPath;
			if(!is_dir($catPath)){
				mkdir($catPath);
			}
		}
		$modifiedCatCount = 0;
		while(($cat = $catQuery->fetch())){
			//--save for use with posts
			$cats[] = $cat['slug'];
			//--store in data
			if($this->categoryPath){
				$catFilePath = $catPath . '/' . $cat['slug'] . '.md';
				$catContent = trim($this->toMarkdownConverter->convert($cat['name'])) . "\n========\n\n" . $this->toMarkdownConverter->convert($cat['description']);
				if(!file_exists($catFilePath) || file_get_contents($catFilePath) !== $catContent){
					echo "writing category file {$catFilePath}\n";
					file_put_contents($catFilePath, $catContent);
					++$modifiedCount;
					++$modifiedCatCount;
				}
			}
		}
		if($modifiedCatCount){
			echo "Wrote {$modifiedCatCount} of " . count($cats) . " categories\n";
		}

		//==posts
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
		$modifiedPostCount = 0;
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
					'excerpt'=> 'post_excerpt',
					'guid'=> 'guid',
					'id'=> 'ID',
					'image'=> 'image',
					'image_alt'=> 'image_alt',
					'modified'=> 'post_modified',
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
							$value = static::getDate($value, $post['post_' . $key . '_gmt']);
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

				//--convert to markdown
				try{
						$content = $this->convertPost($content);
				}catch(Exception $e){
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
				if(empty($meta['categories']) && !empty($this->defaultCategory)){
					$meta['categories'] = [$this->defaultCategory];
				}
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
					++$modifiedPostCount;
				}
			}
			$offset += $this->batch;
		}while($offset < $count);
		echo "Wrote {$modifiedPostCount} of {$realCount} ({$count}) posts\n";

		//==comments
		if($this->commentsPath){
			$i = 0;
			$commentQuery = $this->db->query([
				'values'=> 'this.comment_ID, this.comment_author, this.comment_author_email, this.comment_author_url, this.comment_content, this.comment_date, this.comment_date_gmt, this.comment_parent, this.comment_type, this.user_id, p.ID, p.post_date, p.post_name',
				'table'=> $this->dbPrefix . 'comments',
				'joins'=> [
					'p'=> [
						'on'=> 'p.ID = this.comment_post_ID',
						'table'=> $this->dbPrefix . 'posts',
					],
				],
				'where'=> [
					'comment_approved'=> 1,
				],
			]);
			$modifiedCount = 0;
			$modifiedCommentsCount = 0;
			$posts = [];
			$unaddedComments = [];
			//-# need three loops to build nested hierarchy, handle out of order comments, and then output
			while(($comment = $commentQuery->fetch())){
				$comment = new Comment($comment);
				if(!isset($posts[$comment['ID']])){
					$posts[$comment['ID']] = [
						'comments'=> [],
						'commentsInc'=> 0,
						'mentionsInc'=> 0,
					];
				}
				$posts[$comment['ID']]['comments'][$comment['comment_ID']] = $comment;
				if($comment['comment_parent']){
					if(isset($posts[$comment['ID']]['comments'][$comment['comment_parent']])){
						$posts[$comment['ID']]['comments'][$comment['comment_parent']]->addComment($comment);
					}else{
						$unaddedComments[] = &$comment;
					}
				}else{
					$type = $this->mentionsPath && $comment['comment_type'] !== 'comment' ? 'mention' : 'comment';
					$comment['inc'] = ++$posts[$comment['ID']][$type === 'comment' ? 'commentsInc' : 'mentionsInc'];
				}
			}
			foreach($unaddedComments as $comment){
				$posts[$comment['ID']]['comments'][$comment['comment_parent']]->addComment($comment);
			}
			foreach($posts as &$post){
				$comment = reset($post['comments']);
				$postPath = $this->getPostPath($comment->getPostData());
				$postDirPath = pathinfo($postPath, PATHINFO_DIRNAME) . '/' . pathinfo($postPath, PATHINFO_FILENAME);
				if(!is_dir($postDirPath)){
					mkdir($postDirPath);
				}
				$commentsDir = $postDirPath . $this->commentsPath;
				if(!is_dir($commentsDir)){
					mkdir($commentsDir);
				}
				if($this->mentionsPath){
					$mentionsDir = $postDirPath . $this->mentionsPath;
					if(!is_dir($mentionsDir)){
						mkdir($mentionsDir);
					}
				}
				foreach($post['comments'] as $comment){
					$type = $this->mentionsPath && $comment['comment_type'] !== 'comment' ? 'mention' : 'comment';
					if(!$comment['comment_parent']){
						$changes = $this->putComment($comment, $comment['inc'], $type === 'mention' ? $mentionsDir : $commentsDir);
						$modifiedCount += $changes;
						$modifiedCommentsCount += $changes;
					}
				}
			}
			if($modifiedCommentsCount){
				echo "Wrote {$modifiedCommentsCount} of comments\n";
			}
		}

		return $modifiedCount;
	}
	//-# function for recursive nesting
	protected function putComment($comment, $id, $fileDir){
		$changes = 0;
		$commentFilePath = $fileDir . '/' . $id . '.md';
		try{
			$content = $this->convertPost($comment['comment_content']);
		}catch(Exception $e){
			echo "error converting comment {$comment['comment_ID']}\n";
			$content = $comment['comment_content'];
		}
		$meta = $comment->getMeta();
		$fullContent = "---\n" . Yaml::dump($meta, 1, 1) . "---\n\n" . $content;
		if(!file_exists($commentFilePath) || file_get_contents($commentFilePath) !== $fullContent){
			echo "writing comment file {$commentFilePath}\n";
			file_put_contents($commentFilePath, $fullContent);
			++$changes;
		}
		if($comment->getComments()){
			foreach($comment->getComments() as $key=> $sub){
				$changes += $this->putComment($sub, "{$id}-" . ($key + 1), $fileDir);
			}
		}
		return $changes;
	}
	static public function getDate($date, $gmt = null){
		if($gmt){
			$diff = date_diff(new DateTime($date), new DateTime($gmt));
			$diff = ($diff->invert ? '+' : '-') . str_pad($diff->h, 2, '0', STR_PAD_LEFT) . ':00';
			$date = new DateTime($date . $diff);
		}else{
			$date = new DateTime($date);
		}
		return $date;
	}
	protected function convertPost(string $content){
		//--fix: some posts seem to have wrong line break
		$content = str_replace("\r\n", "\n", $content);

		//--fix: posts seem to have some chars encoded, shouldn't when markdown
		if(strpos($content, '<pre>') === false || strpos($content, '```') !== false){
			$content = htmlspecialchars_decode($content);
		}

		return $this->toMarkdownConverter->convert($content);
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
