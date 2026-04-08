<?php
// ui_test.php - UI Sandbox for RCM Theme
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RCM UI Test</title>

  <!-- Orbitron Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">

  <!-- Theme CSS (your path) -->
  <link rel="stylesheet" href="/assets/rcm.css">

  <style>
    /* Only for this test page layout */
    .grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:16px;
    }
    @media (max-width: 900px){
      .grid{ grid-template-columns: 1fr; }
    }
    .section-title{
      font-family:'Orbitron', sans-serif;
      letter-spacing:2px;
      margin: 0 0 10px 0;
      opacity:.95;
      text-transform: uppercase;
    }
    .row{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      align-items:center;
    }
  </style>
</head>

<body>

  <div class="wrap">
    <h1 class="page-title">UI TEST</h1>

    <!-- ✅ نفس فكرة صفحة announcements: inner-box -->
    <div class="inner-box">

      <!-- Top buttons row (outside the extra panel) -->
      <div class="top">
        <div class="btn-row">
          <a class="btn" href="#">PRIMARY</a>
          <a class="btn" href="#">CANCEL</a>
          <a class="btn sm" href="#">SMALL</a>
          <a class="btn lg" href="#">LARGE</a>
        </div>

        <span class="pill">PILL SAMPLE</span>
      </div>

      <!-- ✅ المربع التاني جوه (زي اللي انت عايزه) -->
      <div class="panel-box">

        <div class="grid">

          <!-- LEFT: FORM -->
          <div>
            <h3 class="section-title">FORM ELEMENTS</h3>

            <form method="post" action="#">
              <label>Text Input</label>
              <input type="text" placeholder="Type here..." value="admin" />

              <label>Password</label>
              <input type="password" placeholder="********" value="password" />

              <label>Select</label>
              <select>
                <option value="">-- Select Destination --</option>
                <option>HANGUP</option>
                <option>QUEUE support</option>
                <option>IVR main</option>
              </select>

              <label>Textarea</label>
              <textarea rows="4" placeholder="Write something..."></textarea>

              <div class="btn-row" style="margin-top:18px;">
                <button class="btn" type="button">TEST BTN</button>
                <button class="btn" type="submit">SUBMIT</button>
              </div>
            </form>

            <div style="margin-top:16px;">
              <span class="pill">INFO: This is a pill message</span>
            </div>
          </div>

          <!-- RIGHT: TABLE -->
          <div>
            <h3 class="section-title">TABLE + ACTIONS</h3>

            <table>
              <thead>
                <tr>
                  <th>Number</th>
                  <th>Name</th>
                  <th>Destination</th>
                  <th>Actions</th>
                </tr>
              </thead>

              <tbody>
                <tr>
                  <td><span class="pill">8888</span></td>
                  <td>rwa</td>
                  <td><span class="pill">HANGUP</span></td>
                  <td class="actions">
                    <a class="btn sm" href="#">EDIT</a>
                    <a class="btn sm" href="#">DELETE</a>
                  </td>
                </tr>

                <tr>
                  <td><span class="pill">9900</span></td>
                  <td>test_ann</td>
                  <td><span class="pill">QUEUE support</span></td>
                  <td class="actions">
                    <a class="btn sm" href="#">EDIT</a>
                    <a class="btn sm" href="#">DELETE</a>
                  </td>
                </tr>
              </tbody>
            </table>

            <div style="margin-top:16px;">
              <h3 class="section-title">MISC</h3>
              <div class="row">
                <span class="pill">SUCCESS</span>
                <span class="pill">WARNING</span>
                <span class="pill">ERROR</span>
              </div>

              <p class="muted" style="margin-top:10px;">
                Muted text example. Use this for hints, notes, small text.
              </p>
            </div>

          </div>

        </div><!-- /grid -->

      </div><!-- /panel-box -->

    </div><!-- /inner-box -->

  </div><!-- /wrap -->

</body>
</html>
