@extends('layouts.app')

@section('content')

    @include('actions.settings.partials.system.cron')

    @include('actions.settings.partials.system.general-settings')

@endsection
