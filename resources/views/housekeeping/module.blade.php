@extends('housekeeping.layout')
@section('title', $title.' | Housekeeping')
@section('content')
<div class="hk-page-heading"><p>HOUSEKEEPING / {{ mb_strtoupper($title) }}</p><h1>{{ $title }}</h1><span>Módulo de administración</span></div>
<section class="hk-placeholder"><x-store.icon name="gear" size="40"/><h2>Espacio de gestión preparado</h2><p>La navegación de {{ mb_strtolower($title) }} está disponible. Las herramientas para consultar y modificar datos se incorporarán en una próxima etapa.</p><a class="hk-button" href="{{ route('housekeeping.dashboard') }}">Volver al resumen</a></section>
@endsection
