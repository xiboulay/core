<?php
/**
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Markus Goetz <markus@woboq.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\Upload;

use Sabre\DAV\IFile;

/**
 * Class AssemblyStream
 *
 * The assembly stream is a virtual stream that wraps multiple chunks.
 * Reading from the stream transparently accessed the underlying chunks and
 * give a representation as if they were already merged together.
 *
 * @package OCA\DAV\Upload
 */
class AssemblyStream implements \Icewind\Streams\File {

	/** @var resource */
	protected $context;

	/** @var IFile[] */
	protected $nodes;

	/** @var int */
	protected $pos = 0;

	/** @var array */
	protected $sortedNodes;

	/** @var int */
	protected $size;

	/** @var resource */
	protected $currentStream = null;

	/** @var IFile */
	protected $currentNode;

	/**
	 * @param string $path
	 * @param string $mode
	 * @param int $options
	 * @param string &$opened_path
	 * @return bool
	 */
	public function stream_open($path, $mode, $options, &$opened_path) {
		$this->loadContext('assembly');

		// sort the nodes
		$nodes = $this->nodes;
		// http://stackoverflow.com/a/10985500
		@\usort($nodes, function (IFile $a, IFile $b) {
			return \strnatcmp($a->getName(), $b->getName());
		});
		$this->nodes = $nodes;

		// build additional information
		$this->sortedNodes = [];
		$start = 0;
		foreach ($this->nodes as $node) {
			$size = $node->getSize();
			$name = $node->getName();
			// ignore .zsync metadata file
			if (!\strcmp($name, ".zsync")) {
				continue;
			}
			$this->sortedNodes[$name] = ['node' => $node, 'start' => $start, 'end' => $start + $size];
			$start += $size;
			$this->size = $start;
		}
		return true;
	}

	/**
	 * @param string $offset
	 * @param int $whence
	 * @return bool
	 */
	public function stream_seek($offset, $whence = SEEK_SET) {
		return false;
	}

	/**
	 * @return int
	 */
	public function stream_tell() {
		return $this->pos;
	}

	/**
	 * @param int $count
	 * @return string
	 */
	public function stream_read($count) {
		do {
			if ($this->currentStream === null) {
				list($node, $posInNode) = $this->getNodeForPosition($this->pos);
				if ($node === null) {
					// reached last node, no more data
					return '';
				}
				$this->currentStream = $this->getStream($node);
				\fseek($this->currentStream, $posInNode);
			}

			$data = \fread($this->currentStream, $count);
			// isset is faster than strlen
			if (isset($data[$count - 1])) {
				// we read the full count
				$read = $count;
			} else {
				// reaching end of stream, which happens less often so strlen is ok
				$read = \strlen($data);
			}

			if (\feof($this->currentStream)) {
				\fclose($this->currentStream);
				$this->currentNode = null;
				$this->currentStream = null;
			}
			// if no data read, try again with the next node because
			// returning empty data can make the caller think there is no more
			// data left to read
		} while ($read === 0);

		// update position
		$this->pos += $read;
		return $data;
	}

	/**
	 * @param string $data
	 * @return int
	 */
	public function stream_write($data) {
		return false;
	}

	/**
	 * @param int $option
	 * @param int $arg1
	 * @param int $arg2
	 * @return bool
	 */
	public function stream_set_option($option, $arg1, $arg2) {
		return false;
	}

	/**
	 * @param int $size
	 * @return bool
	 */
	public function stream_truncate($size) {
		return false;
	}

	/**
	 * @return array
	 */
	public function stream_stat() {
		return [];
	}

	/**
	 * @param int $operation
	 * @return bool
	 */
	public function stream_lock($operation) {
		return false;
	}

	/**
	 * @return bool
	 */
	public function stream_flush() {
		return false;
	}

	/**
	 * @return bool
	 */
	public function stream_eof() {
		return $this->pos >= $this->size;
	}

	/**
	 * @return bool
	 */
	public function stream_close() {
		return true;
	}

	/**
	 * Load the source from the stream context and return the context options
	 *
	 * @param string $name
	 * @return array
	 * @throws \Exception
	 */
	protected function loadContext($name) {
		$context = \stream_context_get_options($this->context);
		if (isset($context[$name])) {
			$context = $context[$name];
		} else {
			throw new \BadMethodCallException('Invalid context, "' . $name . '" options not set');
		}
		if (isset($context['nodes']) and \is_array($context['nodes'])) {
			$this->nodes = $context['nodes'];
		} else {
			throw new \BadMethodCallException('Invalid context, nodes not set');
		}
		return $context;
	}

	/**
	 * @param IFile[] $nodes
	 * @return resource
	 *
	 * @throws \BadMethodCallException
	 */
	public static function wrap(array $nodes, IFile $backingFile = null, $length = null) {
		$context = \stream_context_create([
			'assembly' => [
				'nodes' => $nodes]
		]);
		\stream_wrapper_register('assembly', '\OCA\DAV\Upload\AssemblyStream');
		try {
			$wrapped = \fopen('assembly://', 'r', null, $context);
		} catch (\BadMethodCallException $e) {
			\stream_wrapper_unregister('assembly');
			throw $e;
		}
		\stream_wrapper_unregister('assembly');
		return $wrapped;
	}

	/**
	 * @param $pos
	 * @return IFile | null
	 */
	protected function getNodeForPosition($pos) {
		foreach ($this->sortedNodes as $node) {
			if ($pos >= $node['start'] && $pos < $node['end']) {
				return [$node['node'], $pos - $node['start']];
			}
		}
		return null;
	}

	/**
	 * @param IFile $node
	 * @return resource
	 */
	protected function getStream(IFile $node) {
		$data = $node->get();
		if (\is_resource($data)) {
			return $data;
		}

		return \fopen('data://text/plain,' . $data, 'r');
	}
}
