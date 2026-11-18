<div @if ($this->interval() !== null) wire:poll.{{ $this->interval() }} @endif>
	{{ $this->table }}
</div>
