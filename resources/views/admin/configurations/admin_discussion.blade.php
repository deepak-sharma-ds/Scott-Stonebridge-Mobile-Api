{{-- Extends layout --}}
@extends('admin.layouts.app')

{{-- Content --}}
@section('content')

<div class="container-fluid">
    <div class="row page-titles mx-0 mb-3">
        <div class="col-sm-6 p-0">
            <div class="welcome-text">
                <h4>Configuration</h4>
                <span>{{ Str::ucfirst($prefix) }} Configuration</span>
            </div>
        </div>
        <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.configurations.admin_index') }}">Configuration</a></li>
                <li class="breadcrumb-item active"><a href="javascript:void(0)">{{ Str::ucfirst($prefix) }}</a></li>
            </ol>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ Str::ucfirst($prefix) }} {{ __('common.configuration') }}</h4>
                </div>
                <div class="card-body">
                    <div class="basic-form">
                        <form action="{{ route('admin.configurations.save_config', $prefix) }}" method="post" enctype="multipart/form-data">
                            @csrf
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label">{{ __('common.default_post_settings') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[pingback_flag]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[pingback_flag]" id="PingbackFlag" value="1" @checked(config('Discussion.pingback_flag'))>
                                        <label class="form-check-label" for="PingbackFlag">{{ __('common.attempt_to_notify_blog') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[pingback_status]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[pingback_status]" id="PingbackStatus" value="1" @checked(config('Discussion.pingback_status'))>
                                        <label class="form-check-label" for="PingbackStatus">{{ __('common.allow_link_notifications_form_blog') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[comment_status]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[comment_status]" value="1" id="CommentStatus" @checked(config('Discussion.comment_status'))>
                                        <label class="form-check-label" for="CommentStatus">{{ __('common.allow_people_submit_comments') }}</label>
                                    </div>
                                    <small>{{ __('common.individual_post_may_override') }}</small>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label">{{ __('common.other_comment_settings') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[name_email_require]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[name_email_require]" id="NameEmailRequire" value="1" @checked(config('Discussion.name_email_require'))>
                                        <label class="form-check-label" for="NameEmailRequire">{{ __('common.comment_name_email_required') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[registration_comment]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[registration_comment]" id="RegistrationComment" value="1" @checked(config('Discussion.registration_comment'))>
                                        <label class="form-check-label" for="RegistrationComment">{{ __('common.comment_required_login') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[save_comments_cookie]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[save_comments_cookie]" id="SaveCommentsCookie" value="1" @checked(config('Discussion.save_comments_cookie'))>
                                        <label class="form-check-label" for="SaveCommentsCookie">{{ __('common.enable_comment_author_cookies') }} </label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[thread_comments]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[thread_comments]" id="ThreadComments" value="1" @checked(config('Discussion.thread_comments'))>
                                        <div class="d-flex align-items-center">
                                            <label class="form-check-label" for="ThreadComments"> {{ __('common.enable_nested_comment') }} </label>
                                            <select name="Discussion[thread_comments_depth]" class="form-control w-auto ms-2">
                                                <option value="2" @selected(config('Discussion.thread_comments_depth') == 2)>2</option>
                                                <option value="3" @selected(config('Discussion.thread_comments_depth') == 3)>3</option>
                                                <option value="4" @selected(config('Discussion.thread_comments_depth') == 4)>4</option>
                                                <option value="5" @selected(config('Discussion.thread_comments_depth') == 5)>5</option>
                                                <option value="6" @selected(config('Discussion.thread_comments_depth') == 6)>6</option>
                                                <option value="7" @selected(config('Discussion.thread_comments_depth') == 7)>7</option>
                                                <option value="8" @selected(config('Discussion.thread_comments_depth') == 8)>8</option>
                                                <option value="9" @selected(config('Discussion.thread_comments_depth') == 9)>9</option>
                                                <option value="10" @selected(config('Discussion.thread_comments_depth') == 10)>10</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[page_comments]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[page_comments]" id="PageComments" value="1" @checked(config('Discussion.page_comments'))>
                                        <div class="d-flex align-items-center">
                                            <label class="form-check-label" for="PageComments">{{ __('common.implement_pagination_comment') }}</label> 
                                            <input type="number" name="Discussion[comments_per_page]" class="form-control w-auto" value="{{ config('Discussion.comments_per_page', 50) }}">
                                        </div>
                                    </div>
                                    <div class="form-check d-flex align-items-center">
                                        <select name="Discussion[comment_order]" class="form-control w-auto" id="CommentOrder">
                                            <option value="asc" @selected(config("Discussion.comment_order") == 'asc')>{{ __('common.older') }}</option>
                                            <option value="desc" @selected(config("Discussion.comment_order") == 'desc')>{{ __('common.newer') }}</option>
                                        </select>
                                        <label class="form-check-label" for="CommentOrder">{{ __('common.comments_display_top_page') }}</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label">{{ __('common.email_me_whenever') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[comments_notify]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[comments_notify]" id="DiscussionCommentNotify" value="1" @checked(config('Discussion.comments_notify'))>
                                        <label class="form-check-label" for="DiscussionCommentNotify">{{ __('common.anyone_posts_comment') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[moderation_notify]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[moderation_notify]" id="DiscussionModerationNotify" value="1" @checked(config('Discussion.moderation_notify'))>
                                        <label class="form-check-label" for="DiscussionModerationNotify">{{ __('common.comments_moderation_before') }} </label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label">{{ __('common.before_comment_apprears') }}</label>
                                <div class="col-sm-6 form-group">
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[comment_moderation]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[comment_moderation]" id="DiscussionCommentModeration" value="1" @checked(config('Discussion.comment_moderation'))>
                                        <label class="form-check-label" for="DiscussionCommentModeration">{{ __('common.comment_manually_approved') }} </label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="Discussion[comment_previously_approved]" value="0">
                                        <input class="form-check-input" type="checkbox" name="Discussion[comment_previously_approved]" id="DiscussionCommentPreviouslyApproved" value="1" @checked(config('Discussion.comment_previously_approved'))>
                                        <label class="form-check-label" for="DiscussionCommentPreviouslyApproved">{{ __('common.previously_approved_comment') }} </label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label">{{ __('Comment Moderation') }}</label>
                                <div class="col-sm-6 form-group">
                                    <label>{{ __('common.listed_words_for_pending') }}</label>
                                    <textarea name="Discussion[moderation_keys]" class="form-control h-auto" rows="10">{{ config('Discussion.moderation_keys') }}</textarea>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label">{{ __('Disallowed Comment Keys') }}</label>
                                <div class="col-sm-6 form-group">
                                    <p>{{ __('common.listed_words_for_trash') }}</p>
                                    <textarea name="Discussion[disallowed_comment_keys]" class="form-control h-auto" rows="10">{{ config('Discussion.disallowed_comment_keys') }}</textarea>
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-sm-3"></div>
                                <div class="col-sm-6">
                                    <button type="submit" class="btn btn-primary">Save</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection