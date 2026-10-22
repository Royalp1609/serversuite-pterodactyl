@php
    // Shields-style badge. Widths are estimated (~6.6px per character at 11px).
    $lw = (int) ceil(mb_strlen($label) * 6.6) + 12;
    $vw = (int) ceil(mb_strlen($value) * 6.6) + 12;
    $w  = $lw + $vw;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="{{ $w }}" height="20" role="img" aria-label="{{ $label }}: {{ $value }}">
<title>{{ $label }}: {{ $value }}</title>
<clipPath id="r"><rect width="{{ $w }}" height="20" rx="3"/></clipPath>
<g clip-path="url(#r)">
<rect width="{{ $lw }}" height="20" fill="#334155"/>
<rect x="{{ $lw }}" width="{{ $vw }}" height="20" fill="{{ $colour }}"/>
</g>
<g fill="#fff" text-anchor="middle" font-family="Verdana,DejaVu Sans,sans-serif" font-size="11">
<text x="{{ $lw / 2 }}" y="14">{{ $label }}</text>
<text x="{{ $lw + $vw / 2 }}" y="14">{{ $value }}</text>
</g>
</svg>
