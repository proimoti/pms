@foreach ($properties as $property)<a href="/properties/{{ $property->id }}">№ {{ $property->number }}</a>{{ $loop->last ? '' : ', ' }}@endforeach
