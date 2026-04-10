<?php
namespace TJM\WPToMarkdown;
use ArrayAccess;
use TJM\WPToMarkdown;

class Comment implements ArrayAccess{
	protected array $comments = [];
	protected array $vals = [];
	public function __construct(array $vals){
		foreach($vals as $key=> $val){
			$this->set($key, $val);
		}
	}
	public function get(string $key){
		return $this->vals[$key] ?? null;
	}
	public function set($key, $val = null){
		if(is_array($key)){
			foreach($key as $k2=> $v2){
				$this->set($k2, $v2);
			}
		}else{
			$this->vals[$key] = $val;
		}
	}
	public function addComment(Comment $comment){
		$this->comments[] = $comment;
	}
	public function getComments(){
		return $this->comments;
	}
	public function getMeta(){
		$val = [
			'author'=> $this['comment_author'],
			'date'=> WPToMarkdown::getDate($this['comment_date'], $this['comment_date_gmt'] ?? null),
			'id'=> $this['comment_ID'],
			'type'=> $this['comment_type'],
		];
		if($this['comment_author_email']){
			$val['authorEmail'] = $this['comment_author_email'];
		}
		if($this['comment_author_url']){
			$val['authorURL'] = $this['comment_author_url'];
		}
		if($this['user_id']){
			$val['user'] = $this['user_id'];
		}
		return $val;
	}
	public function getPostData(){
		return [
			'ID'=> $this['id'],
			'post_date'=> $this['post_date'],
			'post_name'=> $this['post_name'],
		];
	}

	//--ArrayAccess
	public function offsetExists($key): bool{
		return isset($this->vals[$key]);
	}
	public function offsetGet($key): mixed{
		return $this->get($key);
	}
	public function offsetSet($key, $val): void{
		$this->set($key, $val);
	}
	public function offsetUnset($key): void{
		unset($this->vals[$key]);
	}
}
