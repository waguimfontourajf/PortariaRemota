' Roda o executar-agendador.bat sem abrir janela (usado pela tarefa
' agendada "PortariaRemota-Agendador", a cada minuto).
Set fso = CreateObject("Scripting.FileSystemObject")
pasta = fso.GetParentFolderName(WScript.ScriptFullName)
CreateObject("WScript.Shell").Run """" & pasta & "\executar-agendador.bat""", 0, True
