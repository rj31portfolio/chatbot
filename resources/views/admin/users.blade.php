@extends('layouts.app')
@section('title','Users')
@section('content')
<div class="page-heading"><div><h1>People behind the businesses.</h1><p>Manage account access across your platform.</p></div></div><section class="panel"><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>@foreach($users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->is_super_admin?'Super admin':'Business user' }}</td><td>{{ $u->status }}</td><td>@if($u->id!==auth()->id())<form method="post" action="/admin/users/{{ $u->id }}">@csrf @method('PUT')<input type="hidden" name="status" value="{{ $u->status==='active'?'suspended':'active' }}"><button class="text-link">{{ $u->status==='active'?'Suspend':'Activate' }}</button></form>@endif</td></tr>@endforeach</tbody></table></div><div class="panel-body">{{ $users->links() }}</div></section>
@endsection
