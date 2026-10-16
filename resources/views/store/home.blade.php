@extends('layouts.store')
@section('content')
<x-store.hero />
<x-store.categories :categories="$categories" />
<x-store.products :products="$products" />
<x-store.services />
<x-store.benefits />
@endsection
