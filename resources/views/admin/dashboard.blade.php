{{-- resources/views/admin/dashboard.blade.php --}}
@extends('layouts.app')

@section('title', 'Dashboard')

{{-- Body lives in the partial so the AJAX branch can render it alone.
     This wrapper is the no-JS / ?full=1 path: same markup, rendered
     synchronously inside the layout. --}}
@section('content')
@include('admin._dashboard-body')
@endsection
