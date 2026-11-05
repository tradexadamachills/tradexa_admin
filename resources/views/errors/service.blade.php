@extends('layouts.auth')
@section('content')<h1>A moment of patience.</h1><p>{{ $message }}</p><a class="button" href="{{ route('reviews.index') }}">Back to review queue</a>@endsection