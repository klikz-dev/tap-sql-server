## Tap Specific Caveats:
- binary datatypes not supported
- non-supported datatypes are converted to text fields
- because of the offset provided by the day_offset configuration value, differential syncs may report numbers different than what is shown in Bytespree (upserts update the original row values; not create new rows)

## Automated Test Coverage
- [] Retry logic
- [] HTTP errors
- [] Empty result set
- [] Exception handling

## Manual Testing

### Set up connector in Bytespree

- [n/a] Verify Oauth success redirects back to Bytespree
- [n/a] Verify Oauth failure (invalid credentials) works as expected, not breaking app flow
- [x] Tap tests credentials for validity
- [x] Tap returns tables appropriately
- [x] Tap returns columns for table settings
- [n/a] Secondary options are populated
- [n/a] Verify conditional logic works within connector settings (will require looking @ definition.json)
- [x] Make sure all fields that are required for authentication are, indeed, required
- [x] Ensure all settings have appropriate description
- [x] Ensure all settings have proper data type (e.g. a checkbox vs a textbox)
- [x] "Known Limitations" is populated and spellchecked
- [x] "Getting Started" is populated and spellchecked
- [x] Make sure tap settings appear in a logical order (e.g. user before password or where conditional logic is applied)
- [x] If tables aren't pre-populated, ensure user can add tables manually by typing them in
- [ ] (FUTURE) Sensitive information should be hidden by default
- [ ] (FUTURE) Test di_partner_integration_tables.minimum_sync_date works as expected (todo: find tap that uses this, maybe rzrs edge)
- [ ] (FUTURE) Ensure 15 minute & hourly sync is disabled if tap is full table replace


### Test Method
- [x] Valid credentials completes test method successfully
- [x] Invalid or incomplete credentials fails test method
- [x] Documented caveats for test methods are provided

### Build Method
- [x] Verify table was created successfully
- [x] Verify indexes were created
- [x] If unique indexes are not used, developer needs to explain why

### Sync Method
- [x] Appropriate column types are assigned
- [n/a] JSON data is in a JSON field
- [x] Spot check 10-15 records pulled in from sync (note: shuf -n 10 output.log --- expanded in Spot Checking)
- [x] Verify the counts match from Jenkins log matches records in Bytespree
- [x] Check for duplicate records, within reason
- [x] Verify columns added to source are added to columns in Bytespree database
- [n/a] When connector supports deleting records, ensure physical deletion occurs

### Differential Syncing (Incremental)
- [x] Ensure last sync date is updated to time sync was started
- [x] Make sure state file is properly passed
- [x] If manually changing last started date, ensure state file is properly passed and tap handles it correctly (try potentially problematic dates e.g. future dates)
- [x] Verify the counts match from Jenkins log matches records in Bytespree when running more than once

## Spot Checking
To spot check, locate the tap log file, `cd` to the `/var/connectors/output/{TEAM}/{DATABASE}/sync/{TABLE}/{JENKINS_BUILD_ID}` folder.

Get 10 random lines of output: `shuf -n 10 output.log`

Get 20 random lines of output: `shuf -n 20 output.log`

## Checking the State File
1. Go to the Jenkins sync folder for a table, e.g. `Dashboard` / `Integrations` / `dev` / `virtuous_dec_13` / `sync` / `campaigns`
2. Click Configure
3. At the bottom, click the X to the right of `Delete workspace when build is done`
4. Click Save
5. Re-run this job
6. In the output for the newly launched job, look for: `Building in workspace /var/lib/jenkins/workspace/Integrations/dev/virtuous_dec_13/sync/campaigns`
7. Navigate to the folder mentioned in Terminal
8. Execute `cat state.json`
9. Inspect the output, make sure it looks to be what you'd expect