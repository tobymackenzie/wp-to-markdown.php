<?php
namespace TJM\WPToMarkdown\Event;
use Symfony\Contracts\EventDispatcher\Event;

class ConvertedContentEvent extends Event{
	protected string $content;
	public function __construct(string $content){
		$this->content = $content;
	}
	public function getContent(){
		return $this->content;
	}
	public function setContent(string $value){
		$this->content = $value;
	}
}
