# SyncGuard Screenshot & Animation Pipeline

This directory contains the automated capture pipeline used to generate the project's documentation images and failure demo animations.

## Architecture

- **`capture.mjs`**: Playwright automation script executed in the official `mcr.microsoft.com/playwright` container attached to the `syncguard_default` Docker network. It programmatically drives actual application states via internal PHP CLI utilities (`bin/demo_submit.php`, `bin/worker.php`, `bin/mock_control.php`, `bin/fast_forward.php`), asserts full DOM stabilization, and captures high-DPI screenshots (1440x900 @2x, 390x844 mobile @2x) and a 1280x720 interactive screen recording.
- **`run.ps1`**: Orchestrates the capture workflow, starts the Playwright container, executes the script, and invokes `jrottenberg/ffmpeg` to transcode the recorded video into an optimized `failure-demo.mp4` and `failure-demo.gif` (&lt;10 MB).
- **Quality Requirements**:
  - No synthetic placeholders, drawings, or mockups. Every image is an authentic capture of `http://web`.
  - Non-blocking DOM verification: no long static sleeps; asserts that the worker online pill is active and dynamic tables are populated.
  - Safe data cleanup: resets queue and mock configs before and after execution.
