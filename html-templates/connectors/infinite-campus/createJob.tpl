{extends designs/site.tpl}

{block title}Pull from Infinite Campus &mdash; {$dwoo.parent}{/block}

{block content}
    <h1>Pull from Infinite Campus</h1>
    <h2>Instructions</h2>
    <ul>
        <li>Save and upload CSV here. Use pretend mode first to check changes.</li>
    </ul>

    <h2>Input</h2>
    <h3>Run from template</h3>
    <ul>
        {foreach item=TemplateJob from=$templates}
            <li><a href="{$connectorBaseUrl}/synchronize/{$TemplateJob->Handle}" title="{$TemplateJob->Config|http_build_query|escape}">Job #{$TemplateJob->ID} &mdash; created by {$TemplateJob->Creator->Username} on {$TemplateJob->Created|date_format:'%c'}</a></li>
        {/foreach}
    </ul>

    <h3>Run or save a new job</h3>
    <form method="POST" enctype="multipart/form-data">
        <fieldset>
            <legend>Job Configuration</legend>
            <p>
                <label>
                    Pretend
                    <input type="checkbox" name="pretend" value="true" {refill field=pretend checked="true" default="true"}>
                </label>
                (Check to prevent saving any changes to the database)
            </p>
            <p>
                <label>
                    Create Template
                    <input type="checkbox" name="createTemplate" value="true" {refill field=createTemplate checked="true"}>
                </label>
                (Check to create a template job that can be repeated automatically instead of running it now)
            </p>
            <p>
                <label>
                    Email report
                    <input type="text" name="reportTo" {refill field=reportTo} length="100">
                </label>
                Email recipient or list of recipients to send post-sync report to
            </p>
        </fieldset>
        <fieldset>
            <legend>User Accounts</legend>
            <p>
                <label>
                    Auto Capitalize
                    <input type="checkbox" name="autoCapitalize" value="true" {refill field=autoCapitalize checked="true"}>
                </label>
                (Check to make best-case at correct capitalization for proper nouns if input case is mangled)
            </p>
            <p>
                <label>
                    Update usernames
                    <input type="checkbox" name="updateUsernames" value="true" {refill field=updateUsernames checked="true"}>
                </label>
                (Check to change a user's username if the site's configured generator comes up with a new one)
            </p>
            <p>
                <label>
                    Students CSV
                    <input type="file" name="students">
                </label>
            </p>
        </fieldset>
        <fieldset>
            <legend>Courses Sections & Enrollments</legend>
            <p>
                <label>
                    Master Term
                    <select name="masterTerm">
                        {foreach item=Term from=Slate\Term::getAllMaster()}
                            <option value="{$Term->Handle}" {refill field=masterTerm selected=$Term->Handle}>{$Term->Title|escape}</option>
                        {/foreach}
                    </select>
                    For sections and schedules, the school year to import in to
                </label>
            </p>
            <p>
                <label>
                    Sections CSV
                    <input type="file" name="sections">
                </label>
            </p>
            <p>
                <label>
                    Schedules CSV
                    <input type="file" name="schedules">
                </label>
            </p>
        </fieldset>

        <input type="submit" value="Synchronize">
    </form>
{/block}