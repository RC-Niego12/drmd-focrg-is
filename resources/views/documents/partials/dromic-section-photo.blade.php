@if(is_array($photo) && str_starts_with($photo['data_url'] ?? '', 'data:image/jpeg;base64,'))
@php
  $photoWidth = max(25, min(75, (float) ($photo['width'] ?? 46)));
  $photoSide = ($photo['side'] ?? 'right') === 'left' ? 'left' : 'right';
  $photoOffset = max(0, min(180, (float) ($photo['offset_y'] ?? 0)));
@endphp
<div style="float:{{ $photoSide }};width:{{ $photoWidth }}%;margin-top:{{ $photoOffset }}px;margin-bottom:7pt;{{ $photoSide === 'left' ? 'margin-right:12pt' : 'margin-left:12pt' }};page-break-inside:avoid">
  <img src="{{ $photo['data_url'] }}" alt="{{ $photo['alt'] ?? $label }}" style="display:block;width:100%;height:auto;">
</div>
@endif
